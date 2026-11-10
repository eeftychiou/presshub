<?php
/**
 * Minimal pure-PHP IMAP client used by the press release inbox.
 *
 * PHP 8.4 removed the bundled `imap` extension, so the plugin speaks the
 * small subset of IMAP4rev1 it needs directly over a socket:
 * LOGIN, CAPABILITY, NAMESPACE, LIST, CREATE, SELECT, UID SEARCH,
 * UID FETCH, UID STORE, UID MOVE (or COPY + \Deleted + EXPUNGE) and LOGOUT.
 *
 * The socket is wrapped in a small transport object so the unit tests can
 * drive the client against a scripted fake server.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Socket transport for the IMAP client (stream_socket_client wrapper).
 */
class PressHub_AI_Imap_Stream_Transport {

    /** @var resource|null */
    private $stream = null;

    /**
     * Open the connection.
     *
     * @param string $host       Hostname.
     * @param int    $port       Port.
     * @param string $encryption 'ssl' (implicit TLS), 'tls' (STARTTLS, done by the client) or 'none'.
     * @param int    $timeout    Connect/read timeout in seconds.
     * @param bool   $verify_ssl Verify the server certificate.
     * @return true|WP_Error
     */
    public function open( string $host, int $port, string $encryption, int $timeout, bool $verify_ssl ) {
        $context = stream_context_create( [
            'ssl' => [
                'verify_peer'       => $verify_ssl,
                'verify_peer_name'  => $verify_ssl,
                'allow_self_signed' => ! $verify_ssl,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ],
        ] );
        $scheme = ( 'ssl' === $encryption ) ? 'ssl' : 'tcp';
        $errno  = 0;
        $errstr = '';
        $stream = @stream_socket_client( "{$scheme}://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context );
        if ( ! $stream ) {
            return new WP_Error( 'imap_connect_failed', sprintf( 'Could not connect to %s:%d (%s)', $host, $port, $errstr ?: 'unknown error' ) );
        }
        stream_set_timeout( $stream, $timeout );
        $this->stream = $stream;
        return true;
    }

    /**
     * Upgrade a plain connection to TLS (after STARTTLS).
     *
     * @return bool
     */
    public function enable_crypto(): bool {
        if ( ! $this->stream ) {
            return false;
        }
        $method = defined( 'STREAM_CRYPTO_METHOD_TLS_CLIENT' ) ? STREAM_CRYPTO_METHOD_TLS_CLIENT : 0;
        return (bool) @stream_socket_enable_crypto( $this->stream, true, $method );
    }

    public function write( string $data ): bool {
        if ( ! $this->stream ) {
            return false;
        }
        $len     = strlen( $data );
        $written = 0;
        while ( $written < $len ) {
            $n = @fwrite( $this->stream, substr( $data, $written ) );
            if ( false === $n || 0 === $n ) {
                return false;
            }
            $written += $n;
        }
        return true;
    }

    /**
     * Read one CRLF-terminated line (terminator included), or null on EOF/timeout.
     */
    public function read_line(): ?string {
        if ( ! $this->stream ) {
            return null;
        }
        $line = @fgets( $this->stream );
        if ( false === $line ) {
            return null;
        }
        return $line;
    }

    /**
     * Read exactly $length bytes, or null on EOF/timeout.
     */
    public function read_bytes( int $length ): ?string {
        if ( ! $this->stream ) {
            return null;
        }
        $buf = '';
        while ( strlen( $buf ) < $length ) {
            $chunk = @fread( $this->stream, min( 65536, $length - strlen( $buf ) ) );
            if ( false === $chunk || '' === $chunk ) {
                $meta = stream_get_meta_data( $this->stream );
                if ( ! empty( $meta['timed_out'] ) || feof( $this->stream ) ) {
                    return null;
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    public function close(): void {
        if ( $this->stream ) {
            @fclose( $this->stream );
        }
        $this->stream = null;
    }
}

/**
 * IMAP client.
 */
class PressHub_AI_Imap_Client {

    /** @var object Transport with open/enable_crypto/write/read_line/read_bytes/close. */
    private $transport;

    /** @var int */
    private $tag_counter = 0;

    /** @var string[] Upper-cased capabilities. */
    private $capabilities = [];

    /** @var string Hierarchy delimiter reported by the server. */
    private $delimiter = '';

    /** @var bool */
    private $connected = false;

    /**
     * @param object|null $transport Optional transport (tests inject a fake one).
     */
    public function __construct( $transport = null ) {
        $this->transport = $transport ?: new PressHub_AI_Imap_Stream_Transport();
    }

    /**
     * Connect and read the greeting (and STARTTLS when requested).
     *
     * @return true|WP_Error
     */
    public function connect( string $host, int $port, string $encryption = 'ssl', int $timeout = 30, bool $verify_ssl = true ) {
        $opened = $this->transport->open( $host, $port, $encryption, $timeout, $verify_ssl );
        if ( is_wp_error( $opened ) ) {
            return $opened;
        }
        $greeting = $this->transport->read_line();
        if ( null === $greeting || 0 !== strpos( $greeting, '* OK' ) ) {
            $this->transport->close();
            return new WP_Error( 'imap_bad_greeting', 'The mail server did not send a valid IMAP greeting: ' . trim( (string) $greeting ) );
        }
        $this->connected = true;
        $this->parse_capability_text( $greeting );

        if ( 'tls' === $encryption ) {
            $res = $this->command( 'STARTTLS' );
            if ( is_wp_error( $res ) ) {
                $this->disconnect();
                return $res;
            }
            if ( ! $this->transport->enable_crypto() ) {
                $this->disconnect();
                return new WP_Error( 'imap_starttls_failed', 'STARTTLS negotiation with the mail server failed.' );
            }
            $this->capabilities = [];
        }
        return true;
    }

    /**
     * Log in. Credentials are sent as literals so any character is allowed.
     *
     * @return true|WP_Error
     */
    public function login( string $username, string $password ) {
        $res = $this->command( 'LOGIN', [ $username, $password ], true );
        if ( is_wp_error( $res ) ) {
            return new WP_Error( 'imap_login_failed', 'Login failed: ' . $res->get_error_message() );
        }
        $this->parse_capability_text( $res['status_line'] );
        if ( empty( $this->capabilities ) ) {
            $cap = $this->command( 'CAPABILITY' );
            if ( ! is_wp_error( $cap ) ) {
                foreach ( $cap['untagged'] as $u ) {
                    $this->parse_capability_text( $u['text'] );
                }
            }
        }
        return true;
    }

    public function has_capability( string $cap ): bool {
        return in_array( strtoupper( $cap ), $this->capabilities, true );
    }

    /**
     * List mailbox names.
     *
     * @return string[]|WP_Error
     */
    public function list_folders() {
        $res = $this->command( 'LIST', [ '', '*' ] );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $folders = [];
        foreach ( $res['untagged'] as $u ) {
            // * LIST (\HasNoChildren) "." "INBOX.Processed"
            if ( preg_match( '/^\* LIST \(([^)]*)\) (NIL|"(?:[^"\\\\]|\\\\.)*") (.+)$/i', rtrim( $u['text'] ), $m ) ) {
                if ( 'NIL' !== strtoupper( $m[2] ) ) {
                    $this->delimiter = stripcslashes( substr( $m[2], 1, -1 ) );
                }
                $name = $m[3];
                if ( ! empty( $u['literals'] ) && preg_match( '/^\{\d+\}$/', trim( $name ) ) ) {
                    $name = $u['literals'][0];
                } elseif ( '"' === substr( $name, 0, 1 ) ) {
                    $name = stripcslashes( substr( $name, 1, -1 ) );
                }
                $folders[] = $name;
            }
        }
        return $folders;
    }

    public function get_delimiter(): string {
        return $this->delimiter;
    }

    /**
     * Personal namespace prefix (e.g. "INBOX." on many Plesk/Courier servers), or ''.
     */
    public function get_personal_namespace_prefix(): string {
        if ( ! $this->has_capability( 'NAMESPACE' ) ) {
            return '';
        }
        $res = $this->command( 'NAMESPACE' );
        if ( is_wp_error( $res ) ) {
            return '';
        }
        foreach ( $res['untagged'] as $u ) {
            if ( preg_match( '/^\* NAMESPACE \(\("((?:[^"\\\\]|\\\\.)*)" (?:NIL|"((?:[^"\\\\]|\\\\.)*)")/i', $u['text'], $m ) ) {
                if ( isset( $m[2] ) && '' !== $m[2] && '' === $this->delimiter ) {
                    $this->delimiter = stripcslashes( $m[2] );
                }
                return stripcslashes( $m[1] );
            }
        }
        return '';
    }

    /**
     * @return true|WP_Error
     */
    public function create_folder( string $name ) {
        $res = $this->command( 'CREATE', [ $name ] );
        return is_wp_error( $res ) ? $res : true;
    }

    /**
     * Select a mailbox.
     *
     * @return array{exists:int}|WP_Error
     */
    public function select( string $folder ) {
        $res = $this->command( 'SELECT', [ $folder ] );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $exists = 0;
        foreach ( $res['untagged'] as $u ) {
            if ( preg_match( '/^\* (\d+) EXISTS/i', $u['text'], $m ) ) {
                $exists = (int) $m[1];
            }
        }
        return [ 'exists' => $exists ];
    }

    /**
     * UID SEARCH with a raw criteria string (caller-controlled, never user input).
     *
     * @return int[]|WP_Error
     */
    public function search_uids( string $criteria ) {
        $res = $this->command( 'UID SEARCH ' . $criteria );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $uids = [];
        foreach ( $res['untagged'] as $u ) {
            if ( preg_match( '/^\* SEARCH ?(.*)$/i', rtrim( $u['text'] ), $m ) ) {
                foreach ( preg_split( '/\s+/', trim( $m[1] ) ) as $n ) {
                    if ( ctype_digit( $n ) ) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }
        sort( $uids );
        return $uids;
    }

    /**
     * Fetch size and internal date for a set of UIDs.
     *
     * @param int[] $uids
     * @return array<int, array{size:int, internal_date:string}>|WP_Error
     */
    public function fetch_meta( array $uids ) {
        if ( empty( $uids ) ) {
            return [];
        }
        $res = $this->command( 'UID FETCH ' . implode( ',', array_map( 'intval', $uids ) ) . ' (UID RFC822.SIZE INTERNALDATE)' );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $out = [];
        foreach ( $res['untagged'] as $u ) {
            if ( preg_match( '/UID (\d+)/i', $u['text'], $um ) ) {
                $size = preg_match( '/RFC822\.SIZE (\d+)/i', $u['text'], $sm ) ? (int) $sm[1] : 0;
                $date = preg_match( '/INTERNALDATE "([^"]+)"/i', $u['text'], $dm ) ? $dm[1] : '';
                $out[ (int) $um[1] ] = [ 'size' => $size, 'internal_date' => $date ];
            }
        }
        return $out;
    }

    /**
     * Fetch the full raw message without setting \Seen.
     *
     * @return string|WP_Error
     */
    public function fetch_raw( int $uid ) {
        $res = $this->command( 'UID FETCH ' . $uid . ' (UID BODY.PEEK[])' );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        foreach ( $res['untagged'] as $u ) {
            if ( preg_match( '/FETCH \(.*UID ' . $uid . '\b/i', $u['text'] ) || preg_match( '/FETCH \(.*BODY\[\]/i', $u['text'] ) ) {
                if ( ! empty( $u['literals'] ) ) {
                    return $u['literals'][0];
                }
            }
        }
        return new WP_Error( 'imap_fetch_empty', sprintf( 'Message UID %d returned no body.', $uid ) );
    }

    /**
     * Add flags/keywords, e.g. '\\Seen' or '$PressHubSkipped'.
     *
     * @return true|WP_Error
     */
    public function add_flags( int $uid, string $flags ) {
        $res = $this->command( 'UID STORE ' . $uid . ' +FLAGS.SILENT (' . $flags . ')' );
        return is_wp_error( $res ) ? $res : true;
    }

    /**
     * Move a message to another folder.
     *
     * @return true|WP_Error
     */
    public function move( int $uid, string $folder ) {
        if ( $this->has_capability( 'MOVE' ) ) {
            $res = $this->command( 'UID MOVE ' . $uid, [ $folder ] );
            return is_wp_error( $res ) ? $res : true;
        }
        $res = $this->command( 'UID COPY ' . $uid, [ $folder ] );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $res = $this->add_flags( $uid, '\\Deleted' );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $res = $this->has_capability( 'UIDPLUS' ) ? $this->command( 'UID EXPUNGE ' . $uid ) : $this->command( 'EXPUNGE' );
        return is_wp_error( $res ) ? $res : true;
    }

    public function disconnect(): void {
        if ( $this->connected ) {
            $this->command( 'LOGOUT' );
        }
        $this->connected = false;
        $this->transport->close();
    }

    /**
     * Send a command and collect its response.
     *
     * @param string   $command      Command verb (and any raw, caller-controlled arguments).
     * @param string[] $args         Arguments to encode as quoted strings (or literals).
     * @param bool     $force_literal Send every argument as a literal.
     * @return array{untagged: array<int, array{text:string, literals:string[]}>, status_line:string}|WP_Error
     */
    public function command( string $command, array $args = [], bool $force_literal = false ) {
        if ( ! $this->connected && 'LOGOUT' !== $command ) {
            return new WP_Error( 'imap_not_connected', 'Not connected to the mail server.' );
        }
        $tag  = 'P' . ( ++$this->tag_counter );
        $line = $tag . ' ' . $command;

        foreach ( $args as $arg ) {
            $arg = (string) $arg;
            if ( $force_literal || preg_match( '/[\x00-\x1f\x7f-\xff"\\\\]/', $arg ) ) {
                // Literal: send "{n}", wait for the "+" continuation, then the bytes.
                if ( ! $this->transport->write( $line . ' {' . strlen( $arg ) . "}\r\n" ) ) {
                    return new WP_Error( 'imap_write_failed', 'Lost connection to the mail server.' );
                }
                $cont = $this->transport->read_line();
                if ( null === $cont || '+' !== substr( $cont, 0, 1 ) ) {
                    return new WP_Error( 'imap_literal_rejected', trim( preg_replace( '/^' . preg_quote( $tag, '/' ) . ' /', '', (string) $cont ) ) ?: 'Server rejected the request.' );
                }
                $line = $arg;
            } else {
                $line .= ' "' . $arg . '"';
            }
        }

        if ( ! $this->transport->write( $line . "\r\n" ) ) {
            return new WP_Error( 'imap_write_failed', 'Lost connection to the mail server.' );
        }

        $untagged = [];
        while ( true ) {
            $resp = $this->read_response();
            if ( null === $resp ) {
                $this->connected = false;
                return new WP_Error( 'imap_read_failed', 'The mail server closed the connection or timed out.' );
            }
            $text = $resp['text'];
            if ( 0 === strpos( $text, $tag . ' ' ) ) {
                $status = substr( $text, strlen( $tag ) + 1 );
                if ( 0 === stripos( $status, 'OK' ) ) {
                    return [ 'untagged' => $untagged, 'status_line' => $status ];
                }
                return new WP_Error( 'imap_command_failed', trim( preg_replace( '/^(NO|BAD)\s*/i', '', trim( $status ) ) ) ?: 'Command failed.' );
            }
            if ( 'LOGOUT' === $command && 0 === strpos( $text, '* BYE' ) ) {
                continue;
            }
            $untagged[] = $resp;
        }
    }

    /**
     * Read one logical response line, collecting any {n} literals it carries.
     *
     * @return array{text:string, literals:string[]}|null
     */
    private function read_response(): ?array {
        $text     = '';
        $literals = [];
        while ( true ) {
            $line = $this->transport->read_line();
            if ( null === $line ) {
                return null;
            }
            if ( preg_match( '/\{(\d+)\}\r?\n$/', $line, $m ) ) {
                $text .= rtrim( $line, "\r\n" );
                $data  = $this->transport->read_bytes( (int) $m[1] );
                if ( null === $data ) {
                    return null;
                }
                $literals[] = $data;
                continue;
            }
            $text .= rtrim( $line, "\r\n" );
            return [ 'text' => $text, 'literals' => $literals ];
        }
    }

    private function parse_capability_text( string $text ): void {
        if ( preg_match( '/CAPABILITY ([^\]\r\n]+)/i', $text, $m ) ) {
            $this->capabilities = array_values( array_filter( array_map( 'strtoupper', preg_split( '/\s+/', trim( $m[1] ) ) ) ) );
        }
    }
}
