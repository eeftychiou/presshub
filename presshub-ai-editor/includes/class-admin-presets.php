<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-preset-ui.php';

/**
 * Admin submenu page: PressHub AI → Instruction Presets.
 *
 * Renders the plugin-default preset library (design doc §5.2) and lets
 * admins create / edit / toggle / delete presets. All mutations are
 * delegated to the AJAX handlers in class-ajax-handlers.php
 * (presshub_ai_save_preset / presshub_ai_delete_preset with
 * scope=plugin); this file only renders the page. The jQuery that posts
 * to those endpoints lives in assets/presets.js, enqueued with
 * wp_localize_script (presshubAI.ajax_url + presshubAI.nonce +
 * scope=plugin + translated strings).
 *
 * Self-registering: the file bottom hooks plugins_loaded so no other
 * wiring is needed once the file is require_once'd.
 */

class PressHub_AI_Admin_Presets {

    /** Script handle shared by the admin page and the author profiles. */
    const SCRIPT_HANDLE = 'presshub-ai-presets-js';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_presets_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    /**
     * Enqueue the shared presets asset on the admin library page, with the
     * plugin-scope config (and translated strings) localized for it.
     */
    public function enqueue_scripts( $hook ) {
        if ( 'settings_page_presshub-ai-presets' !== $hook ) {
            return;
        }
        wp_enqueue_script( self::SCRIPT_HANDLE, PRESSHUB_AI_URL . 'assets/presets.js', [ 'jquery', 'wp-i18n' ], PRESSHUB_AI_VERSION, true );
        wp_localize_script( self::SCRIPT_HANDLE, 'presshubAI', self::localize_args( 'plugin' ) );
    }

    /**
     * Shared wp_localize_script payload for the presets asset. Both the
     * admin page (scope=plugin) and the author profile sections
     * (scope=author, user_id set) localize the same object so the JS has
     * one place to read config + translations from.
     */
    public static function localize_args( string $scope, int $user_id = 0 ): array {
        return [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'presshub_ai_nonce' ),
            'scope'    => $scope,
            'user_id'  => $user_id,
            'i18n'     => [
                'slug_required'    => __( 'Name must contain at least one letter or number to generate a slug.', 'presshub-ai-editor' ),
                'error_prefix'     => __( 'Error: ', 'presshub-ai-editor' ),
                'unknown_error'    => __( 'Unknown error.', 'presshub-ai-editor' ),
                'connection_error' => __( 'Server connection error.', 'presshub-ai-editor' ),
                'delete_confirm'   => __( 'Delete this preset?', 'presshub-ai-editor' ),
            ],
        ];
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

        $presets = PressHub_AI_Preset_Store::get_plugin_defaults();

        // Org defaults (2026-08-15 design §9 Q3 / Antigravity A-6):
        // per-category + per-role preset defaults, admin-curated. The
        // selects below post to presshub_ai_save_org_default via
        // assets/presets.js (data-scope / data-key attributes).
        $taxonomy_presets = PressHub_AI_Preset_Store::get_taxonomy_presets();
        $role_presets     = PressHub_AI_Preset_Store::get_role_presets();

        $org_options = [];
        foreach ( $presets as $preset ) {
            if ( empty( $preset['enabled'] ) ) {
                continue;
            }
            $org_options[] = [ 'value' => $preset['slug'], 'label' => $preset['name'] ];
        }

        $category_terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] );
        if ( ! is_array( $category_terms ) ) {
            $category_terms = [];
        }

        // Standard WP roles. Roles with custom slugs are not configurable
        // here (the org-default map sanitizer only accepts slug-safe keys).
        $org_roles = [
            'administrator' => __( 'Administrator', 'presshub-ai-editor' ),
            'editor'        => __( 'Editor', 'presshub-ai-editor' ),
            'author'        => __( 'Author', 'presshub-ai-editor' ),
            'contributor'   => __( 'Contributor', 'presshub-ai-editor' ),
            'subscriber'    => __( 'Subscriber', 'presshub-ai-editor' ),
        ];
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
                        <td class="presshub-preset-text"><?php echo esc_html( PressHub_AI_Preset_UI::excerpt( $preset['instruction_text'], 120 ) ); ?></td>
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

            <h2><?php echo esc_html( __( 'Defaults by category & role', 'presshub-ai-editor' ) ); ?></h2>
            <p class="description">
                <?php echo esc_html( __( 'Assign a default preset per category or per role. A matching category default beats a role default, which beats an author default. Choose “— Disable presets —” to force no preset for that category or role.', 'presshub-ai-editor' ) ); ?>
            </p>

            <h3><?php echo esc_html( __( 'By category', 'presshub-ai-editor' ) ); ?></h3>
            <table class="widefat striped" id="presshub-ai-org-taxonomy-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html( __( 'Category', 'presshub-ai-editor' ) ); ?></th>
                        <th><?php echo esc_html( __( 'Default preset', 'presshub-ai-editor' ) ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $category_terms ) ) : ?>
                    <tr>
                        <td colspan="2"><?php echo esc_html( __( 'No categories found.', 'presshub-ai-editor' ) ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $category_terms as $term ) : ?>
                        <?php if ( ! is_object( $term ) || empty( $term->slug ) ) { continue; } ?>
                        <tr>
                            <td><?php echo esc_html( isset( $term->name ) ? $term->name : $term->slug ); ?></td>
                            <td>
                                <select class="presshub-org-default-select" data-scope="taxonomy" data-key="<?php echo esc_attr( $term->slug ); ?>">
                                    <?php echo $this->org_default_options( $taxonomy_presets[ $term->slug ] ?? '', $org_options ); ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h3><?php echo esc_html( __( 'By role', 'presshub-ai-editor' ) ); ?></h3>
            <table class="widefat striped" id="presshub-ai-org-role-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html( __( 'Role', 'presshub-ai-editor' ) ); ?></th>
                        <th><?php echo esc_html( __( 'Default preset', 'presshub-ai-editor' ) ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $org_roles as $role => $label ) : ?>
                    <tr>
                        <td><?php echo esc_html( $label ); ?></td>
                        <td>
                            <select class="presshub-org-default-select" data-scope="role" data-key="<?php echo esc_attr( $role ); ?>">
                                <?php echo $this->org_default_options( $role_presets[ $role ] ?? '', $org_options ); ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the <option> list for an org-default <select>: no default,
     * the disable sentinel, then every enabled plugin-default preset.
     * The current assignment is marked selected ('' = none assigned).
     *
     * @param string $current Current stored value for this key.
     * @param array  $options Enabled plugin-default options
     *                        ([ 'value' => slug, 'label' => name ]).
     * @return string
     */
    private function org_default_options( string $current, array $options ): string {
        $out  = '<option value="">' . esc_html( __( '— No default —', 'presshub-ai-editor' ) ) . '</option>';
        $out .= '<option value="' . esc_attr( PressHub_AI_Preset_Store::ORG_NONE ) . '"' . ( $current === PressHub_AI_Preset_Store::ORG_NONE ? ' selected="selected"' : '' ) . '>' . esc_html( __( '— Disable presets —', 'presshub-ai-editor' ) ) . '</option>';
        foreach ( $options as $option ) {
            $out .= '<option value="' . esc_attr( $option['value'] ) . '"' . ( $current === $option['value'] ? ' selected="selected"' : '' ) . '>' . esc_html( $option['label'] ) . '</option>';
        }
        return $out;
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
}

// Self-register: no other wiring needed once this file is require_once'd.
add_action( 'plugins_loaded', function() {
    new PressHub_AI_Admin_Presets();
} );
