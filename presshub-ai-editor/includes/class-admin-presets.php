<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin submenu page: PressHub AI → Instruction Presets.
 *
 * Renders the plugin-default preset library (design doc §5.2) and lets
 * admins create / edit / toggle / delete presets. All mutations are
 * delegated to the AJAX handlers in class-ajax-handlers.php
 * (presshub_ai_save_preset / presshub_ai_delete_preset with
 * scope=plugin); this file only renders the page plus a small
 * self-contained inline script that posts to those endpoints via
 * jQuery.ajax (presshubAI.ajax_url + presshubAI.nonce).
 *
 * Self-registering: the file bottom hooks plugins_loaded so no other
 * wiring is needed once the file is require_once'd.
 */

class PressHub_AI_Admin_Presets {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_presets_submenu' ] );
    }

    /**
     * The capability required to manage plugin-default presets.
     * Filterable, mirroring the settings page gate (P3).
     */
    public function settings_cap(): string {
        return (string) apply_filters( 'presshub_ai_settings_cap', 'manage_options' );
    }

    public function add_presets_submenu() {
        add_submenu_page(
            'options-general.php',
            __( 'PressHub AI — Instruction Presets', 'presshub-ai-editor' ),
            __( 'PressHub AI Presets', 'presshub-ai-editor' ),
            $this->settings_cap(),
            'presshub-ai-presets',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        if ( ! current_user_can( $this->settings_cap() ) ) {
            wp_die( __( 'You do not have permission to access this page.', 'presshub-ai-editor' ) );
        }

        $this->ensure_store();

        // Seed the curated plugin defaults lazily on first load
        // (idempotent — an existing library is never overwritten).
        if ( false === get_option( PressHub_AI_Preset_Store::OPTION_DEFAULT_PRESETS, false ) ) {
            PressHub_AI_Preset_Store::seed_plugin_defaults();
        }

        $presets  = PressHub_AI_Preset_Store::get_plugin_defaults();
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'presshub_ai_nonce' );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( __( 'PressHub AI — Instruction Presets', 'presshub-ai-editor' ) ); ?></h1>
            <p class="description">
                <?php echo esc_html( __( 'Plugin-default presets are available to every author as a starting point. Presets are appended to a fixed editorial system prompt — they cannot replace it.', 'presshub-ai-editor' ) ); ?>
            </p>

            <h2><?php echo esc_html( __( 'Plugin defaults', 'presshub-ai-editor' ) ); ?></h2>
            <table class="widefat striped" id="presshub-ai-presets-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html( __( 'Name', 'presshub-ai-editor' ) ); ?></th>
                        <th><?php echo esc_html( __( 'Slug', 'presshub-ai-editor' ) ); ?></th>
                        <th><?php echo esc_html( __( 'Instruction text', 'presshub-ai-editor' ) ); ?></th>
                        <th><?php echo esc_html( __( 'Enabled', 'presshub-ai-editor' ) ); ?></th>
                        <th><?php echo esc_html( __( 'Actions', 'presshub-ai-editor' ) ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $presets ) ) : ?>
                    <tr>
                        <td colspan="5"><?php echo esc_html( __( 'No presets yet. Add one below.', 'presshub-ai-editor' ) ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $presets as $preset ) : ?>
                    <tr class="presshub-preset-row" data-slug="<?php echo esc_attr( $preset['slug'] ); ?>" data-text="<?php echo esc_attr( $preset['instruction_text'] ); ?>">
                        <td class="presshub-preset-name"><?php echo esc_html( $preset['name'] ); ?></td>
                        <td><code><?php echo esc_html( $preset['slug'] ); ?></code></td>
                        <td class="presshub-preset-text"><?php echo esc_html( $this->excerpt( $preset['instruction_text'], 120 ) ); ?></td>
                        <td>
                            <input type="checkbox" class="presshub-preset-enabled"<?php echo $preset['enabled'] ? ' checked="checked"' : ''; ?> />
                        </td>
                        <td>
                            <button type="button" class="button presshub-preset-edit"><?php echo esc_html( __( 'Edit', 'presshub-ai-editor' ) ); ?></button>
                            <button type="button" class="button presshub-preset-delete"><?php echo esc_html( __( 'Delete', 'presshub-ai-editor' ) ); ?></button>
                        </td>
                    </tr>
                    <tr class="presshub-preset-edit-row" style="display:none;">
                        <td colspan="5">
                            <p>
                                <label><?php echo esc_html( __( 'Name', 'presshub-ai-editor' ) ); ?><br />
                                    <input type="text" class="presshub-edit-name regular-text" maxlength="80" value="<?php echo esc_attr( $preset['name'] ); ?>" />
                                </label>
                            </p>
                            <p>
                                <label><?php echo esc_html( __( 'Instruction text', 'presshub-ai-editor' ) ); ?><br />
                                    <textarea class="presshub-edit-text large-text" rows="3" maxlength="4000"><?php echo esc_textarea( $preset['instruction_text'] ); ?></textarea>
                                </label>
                            </p>
                            <p>
                                <button type="button" class="button button-primary presshub-edit-save"><?php echo esc_html( __( 'Save', 'presshub-ai-editor' ) ); ?></button>
                                <button type="button" class="button presshub-edit-cancel"><?php echo esc_html( __( 'Cancel', 'presshub-ai-editor' ) ); ?></button>
                            </p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html( __( 'Add preset', 'presshub-ai-editor' ) ); ?></h2>
            <form id="presshub-ai-add-preset-form">
                <p>
                    <label for="presshub-ai-new-preset-name"><?php echo esc_html( __( 'Name', 'presshub-ai-editor' ) ); ?><br />
                        <input type="text" id="presshub-ai-new-preset-name" class="regular-text" maxlength="80" />
                    </label>
                </p>
                <p>
                    <label for="presshub-ai-new-preset-text"><?php echo esc_html( __( 'Instruction text', 'presshub-ai-editor' ) ); ?><br />
                        <textarea id="presshub-ai-new-preset-text" class="large-text" rows="4" maxlength="4000"></textarea>
                    </label>
                </p>
                <p class="description">
                    <?php echo esc_html( __( 'The slug is generated automatically from the name (e.g. "Concise wire style" → "concise-wire-style").', 'presshub-ai-editor' ) ); ?>
                </p>
                <button type="submit" class="button button-primary"><?php echo esc_html( __( 'Add preset', 'presshub-ai-editor' ) ); ?></button>
            </form>
        </div>

        <script>
        (function($) {
            var presshubAI = {
                ajax_url: <?php echo wp_json_encode( $ajax_url ); ?>,
                nonce: <?php echo wp_json_encode( $nonce ); ?>
            };

            /** Kebab-case slug from a display name (mirrors the server-side slug regex). */
            function presshubPresetSlug(name) {
                return String(name || '')
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '')
                    .slice(0, 40);
            }

            /** POST a preset mutation; reload the page on success, alert on error. */
            function presshubPostPreset(data) {
                data.nonce = presshubAI.nonce;
                return $.ajax({
                    url: presshubAI.ajax_url,
                    type: 'POST',
                    data: data
                }).done(function(response) {
                    if (response && response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (response && response.data ? response.data : 'Unknown error.'));
                    }
                }).fail(function() {
                    alert('Server connection error.');
                });
            }

            $('#presshub-ai-add-preset-form').on('submit', function(e) {
                e.preventDefault();
                var name = $('#presshub-ai-new-preset-name').val();
                var text = $('#presshub-ai-new-preset-text').val();
                var slug = presshubPresetSlug(name);
                if (!slug) {
                    alert('Name must contain at least one letter or number to generate a slug.');
                    return;
                }
                presshubPostPreset({
                    action: 'presshub_ai_save_preset',
                    scope: 'plugin',
                    slug: slug,
                    name: name,
                    instruction_text: text,
                    enabled: 1
                });
            });

            $('.presshub-preset-delete').on('click', function() {
                var $row = $(this).closest('.presshub-preset-row');
                if (!window.confirm('Delete this preset?')) {
                    return;
                }
                presshubPostPreset({
                    action: 'presshub_ai_delete_preset',
                    scope: 'plugin',
                    slug: $row.data('slug')
                });
            });

            $('.presshub-preset-enabled').on('change', function() {
                var $row = $(this).closest('.presshub-preset-row');
                presshubPostPreset({
                    action: 'presshub_ai_save_preset',
                    scope: 'plugin',
                    slug: $row.data('slug'),
                    name: $row.find('.presshub-preset-name').text(),
                    instruction_text: $row.data('text') || '',
                    enabled: this.checked ? 1 : 0
                });
            });

            $('.presshub-preset-edit').on('click', function() {
                var $row = $(this).closest('.presshub-preset-row');
                var $editRow = $row.next('.presshub-preset-edit-row');
                $editRow.find('.presshub-edit-name').val($row.find('.presshub-preset-name').text());
                $editRow.find('.presshub-edit-text').val($row.data('text') || '');
                $row.hide();
                $editRow.show();
            });

            $('.presshub-edit-cancel').on('click', function() {
                var $editRow = $(this).closest('.presshub-preset-edit-row');
                $editRow.hide();
                $editRow.prev('.presshub-preset-row').show();
            });

            $('.presshub-edit-save').on('click', function() {
                var $editRow = $(this).closest('.presshub-preset-edit-row');
                var $row = $editRow.prev('.presshub-preset-row');
                presshubPostPreset({
                    action: 'presshub_ai_save_preset',
                    scope: 'plugin',
                    slug: $row.data('slug'),
                    name: $editRow.find('.presshub-edit-name').val(),
                    instruction_text: $editRow.find('.presshub-edit-text').val(),
                    enabled: $row.find('.presshub-preset-enabled').is(':checked') ? 1 : 0
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Lazy-load the preset store classes when they haven't been required
     * yet (defensive: this file may be loaded before the plugin's main
     * include block in some flows).
     */
    private function ensure_store(): void {
        if ( ! class_exists( 'PressHub_AI_Preset_Store' ) ) {
            require_once __DIR__ . '/class-preset-sanitizer.php';
            require_once __DIR__ . '/class-preset-store.php';
        }
    }

    /**
     * Plain-text excerpt for table cells (no WP formatting dependency).
     */
    private function excerpt( string $text, int $length = 120 ): string {
        $text = trim( $text );
        if ( strlen( $text ) <= $length ) {
            return $text;
        }
        return substr( $text, 0, $length ) . '…';
    }
}

// Self-register: no other wiring needed once this file is require_once'd.
add_action( 'plugins_loaded', function() {
    new PressHub_AI_Admin_Presets();
} );
