<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Author profile section: "PressHub AI Presets".
 *
 * Renders on the profile screens (own profile via show_user_profile and
 * other users' profiles via edit_user_profile — design doc §5.3). The
 * section shows the VIEWED user's presets (add / edit / delete), a
 * "Default preset" selector, and a "Copy from plugin defaults" control.
 *
 * All mutations are delegated to the AJAX handlers in
 * class-ajax-handlers.php with scope=author and the viewed user's id in
 * the payload (user_id POST param); the handlers enforce the
 * ownership/admin capability checks. This file only renders the section
 * plus a small self-contained inline script that posts to those
 * endpoints via jQuery.ajax (presshubAI.ajax_url + presshubAI.nonce).
 *
 * Self-registering: the file bottom hooks plugins_loaded so no other
 * wiring is needed once the file is require_once'd.
 */

class PressHub_AI_Author_Presets {

    public function __construct() {
        add_action( 'show_user_profile', [ $this, 'render_presets_section' ] );
        add_action( 'edit_user_profile', [ $this, 'render_presets_section' ] );
    }

    /**
     * Render the section for the profile screen currently being viewed.
     *
     * @param object|WP_User $user The viewed user.
     */
    public function render_presets_section( $user ) {
        if ( ! is_object( $user ) || empty( $user->ID ) ) {
            return;
        }
        $user_id = (int) $user->ID;

        $this->ensure_store();

        // Seed the curated plugin defaults lazily on first load
        // (idempotent — an existing library is never overwritten).
        if ( false === get_option( PressHub_AI_Preset_Store::OPTION_DEFAULT_PRESETS, false ) ) {
            PressHub_AI_Preset_Store::seed_plugin_defaults();
        }

        $author_presets  = PressHub_AI_Preset_Store::get_author_presets( $user_id );
        $plugin_defaults = PressHub_AI_Preset_Store::get_plugin_defaults();
        $disabled        = PressHub_AI_Preset_Store::get_disabled_defaults( $user_id );
        $default_slug    = PressHub_AI_Preset_Store::get_author_default_slug( $user_id );

        // Default-preset options: the user's enabled presets first, then
        // enabled plugin defaults they haven't disabled (labelled
        // " (default)"). On a slug collision the user's own preset wins.
        $default_options = [];
        $seen            = [];
        foreach ( $author_presets as $preset ) {
            if ( empty( $preset['enabled'] ) ) {
                continue;
            }
            $seen[ $preset['slug'] ] = true;
            $default_options[] = [ 'value' => $preset['slug'], 'label' => $preset['name'] ];
        }
        foreach ( $plugin_defaults as $preset ) {
            if ( empty( $preset['enabled'] ) ) {
                continue;
            }
            if ( in_array( $preset['slug'], $disabled, true ) ) {
                continue;
            }
            if ( isset( $seen[ $preset['slug'] ] ) ) {
                continue;
            }
            $seen[ $preset['slug'] ] = true;
            $default_options[] = [ 'value' => $preset['slug'], 'label' => $preset['name'] . ' (default)' ];
        }

        // Copy source: every enabled plugin default.
        $copy_options = [];
        foreach ( $plugin_defaults as $preset ) {
            if ( empty( $preset['enabled'] ) ) {
                continue;
            }
            $copy_options[] = [ 'value' => $preset['slug'], 'label' => $preset['name'] ];
        }

        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'presshub_ai_nonce' );
        ?>
        <h2><?php echo esc_html( __( 'PressHub AI Presets', 'presshub-ai-editor' ) ); ?></h2>
        <div id="presshub-ai-author-presets" data-user-id="<?php echo esc_attr( $user_id ); ?>">
            <p class="description">
                <?php echo esc_html( __( 'Named instruction presets are appended to the fixed editorial system prompt for your AI drafts — they cannot replace it.', 'presshub-ai-editor' ) ); ?>
            </p>

            <h3><?php echo esc_html( __( 'My presets', 'presshub-ai-editor' ) ); ?></h3>
            <table class="widefat striped" id="presshub-ai-author-presets-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html( __( 'Name', 'presshub-ai-editor' ) ); ?></th>
                        <th><?php echo esc_html( __( 'Slug', 'presshub-ai-editor' ) ); ?></th>
                        <th><?php echo esc_html( __( 'Instruction text', 'presshub-ai-editor' ) ); ?></th>
                        <th><?php echo esc_html( __( 'Actions', 'presshub-ai-editor' ) ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $author_presets ) ) : ?>
                    <tr>
                        <td colspan="4"><?php echo esc_html( __( 'No presets yet. Add one below.', 'presshub-ai-editor' ) ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $author_presets as $preset ) : ?>
                    <tr class="presshub-preset-row" data-slug="<?php echo esc_attr( $preset['slug'] ); ?>" data-text="<?php echo esc_attr( $preset['instruction_text'] ); ?>">
                        <td class="presshub-preset-name"><?php echo esc_html( $preset['name'] ); ?></td>
                        <td><code><?php echo esc_html( $preset['slug'] ); ?></code></td>
                        <td class="presshub-preset-text"><?php echo esc_html( $this->excerpt( $preset['instruction_text'], 120 ) ); ?></td>
                        <td>
                            <button type="button" class="button presshub-preset-edit"><?php echo esc_html( __( 'Edit', 'presshub-ai-editor' ) ); ?></button>
                            <button type="button" class="button presshub-preset-delete"><?php echo esc_html( __( 'Delete', 'presshub-ai-editor' ) ); ?></button>
                        </td>
                    </tr>
                    <tr class="presshub-preset-edit-row" style="display:none;">
                        <td colspan="4">
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

            <h3><?php echo esc_html( __( 'Add preset', 'presshub-ai-editor' ) ); ?></h3>
            <form id="presshub-ai-author-add-preset-form">
                <p>
                    <label for="presshub-ai-author-new-preset-name"><?php echo esc_html( __( 'Name', 'presshub-ai-editor' ) ); ?><br />
                        <input type="text" id="presshub-ai-author-new-preset-name" class="regular-text" maxlength="80" />
                    </label>
                </p>
                <p>
                    <label for="presshub-ai-author-new-preset-text"><?php echo esc_html( __( 'Instruction text', 'presshub-ai-editor' ) ); ?><br />
                        <textarea id="presshub-ai-author-new-preset-text" class="large-text" rows="4" maxlength="4000"></textarea>
                    </label>
                </p>
                <p class="description">
                    <?php echo esc_html( __( 'The slug is generated automatically from the name (e.g. "Concise wire style" → "concise-wire-style").', 'presshub-ai-editor' ) ); ?>
                </p>
                <button type="submit" class="button button-primary"><?php echo esc_html( __( 'Add preset', 'presshub-ai-editor' ) ); ?></button>
            </form>

            <h3><?php echo esc_html( __( 'Default preset', 'presshub-ai-editor' ) ); ?></h3>
            <p class="description">
                <?php echo esc_html( __( 'Applied automatically when no preset is picked in the post editor.', 'presshub-ai-editor' ) ); ?>
            </p>
            <select id="presshub-ai-default-preset">
                <option value=""><?php echo esc_html( __( '— No default —', 'presshub-ai-editor' ) ); ?></option>
                <?php foreach ( $default_options as $option ) : ?>
                    <option value="<?php echo esc_attr( $option['value'] ); ?>"<?php echo $default_slug === $option['value'] ? ' selected="selected"' : ''; ?>><?php echo esc_html( $option['label'] ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button presshub-ai-save-default"><?php echo esc_html( __( 'Save default', 'presshub-ai-editor' ) ); ?></button>

            <h3><?php echo esc_html( __( 'Copy from plugin defaults', 'presshub-ai-editor' ) ); ?></h3>
            <p class="description">
                <?php echo esc_html( __( 'Copy a plugin-default preset into your own library so you can edit it.', 'presshub-ai-editor' ) ); ?>
            </p>
            <?php if ( empty( $copy_options ) ) : ?>
                <p class="description"><?php echo esc_html( __( 'No plugin defaults available.', 'presshub-ai-editor' ) ); ?></p>
            <?php else : ?>
                <select id="presshub-ai-copy-preset">
                    <?php foreach ( $copy_options as $option ) : ?>
                        <option value="<?php echo esc_attr( $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button presshub-ai-copy-preset-btn"><?php echo esc_html( __( 'Copy to my presets', 'presshub-ai-editor' ) ); ?></button>
            <?php endif; ?>
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

            var $section = $('#presshub-ai-author-presets');
            var userId = $section.data('user-id');

            $('#presshub-ai-author-add-preset-form').on('submit', function(e) {
                e.preventDefault();
                var name = $('#presshub-ai-author-new-preset-name').val();
                var text = $('#presshub-ai-author-new-preset-text').val();
                var slug = presshubPresetSlug(name);
                if (!slug) {
                    alert('Name must contain at least one letter or number to generate a slug.');
                    return;
                }
                presshubPostPreset({
                    action: 'presshub_ai_save_preset',
                    scope: 'author',
                    user_id: userId,
                    slug: slug,
                    name: name,
                    instruction_text: text,
                    enabled: 1
                });
            });

            $section.find('.presshub-preset-delete').on('click', function() {
                var $row = $(this).closest('.presshub-preset-row');
                if (!window.confirm('Delete this preset?')) {
                    return;
                }
                presshubPostPreset({
                    action: 'presshub_ai_delete_preset',
                    scope: 'author',
                    user_id: userId,
                    slug: $row.data('slug')
                });
            });

            $section.find('.presshub-preset-edit').on('click', function() {
                var $row = $(this).closest('.presshub-preset-row');
                var $editRow = $row.next('.presshub-preset-edit-row');
                $editRow.find('.presshub-edit-name').val($row.find('.presshub-preset-name').text());
                $editRow.find('.presshub-edit-text').val($row.data('text') || '');
                $row.hide();
                $editRow.show();
            });

            $section.find('.presshub-edit-cancel').on('click', function() {
                var $editRow = $(this).closest('.presshub-preset-edit-row');
                $editRow.hide();
                $editRow.prev('.presshub-preset-row').show();
            });

            $section.find('.presshub-edit-save').on('click', function() {
                var $editRow = $(this).closest('.presshub-preset-edit-row');
                var $row = $editRow.prev('.presshub-preset-row');
                presshubPostPreset({
                    action: 'presshub_ai_save_preset',
                    scope: 'author',
                    user_id: userId,
                    slug: $row.data('slug'),
                    name: $editRow.find('.presshub-edit-name').val(),
                    instruction_text: $editRow.find('.presshub-edit-text').val(),
                    enabled: 1
                });
            });

            $('#presshub-ai-save-default').on('click', function() {
                presshubPostPreset({
                    action: 'presshub_ai_set_default_preset',
                    user_id: userId,
                    slug: $('#presshub-ai-default-preset').val() || ''
                });
            });

            $('.presshub-ai-copy-preset-btn').on('click', function() {
                presshubPostPreset({
                    action: 'presshub_ai_copy_default_preset',
                    user_id: userId,
                    slug: $('#presshub-ai-copy-preset').val() || ''
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
    new PressHub_AI_Author_Presets();
} );
