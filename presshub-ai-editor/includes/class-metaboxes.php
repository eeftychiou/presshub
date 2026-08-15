<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PressHub_AI_Metaboxes {
    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_coauthor_metabox' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets( $hook ) {
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'presshub-ai-admin-css', PRESSHUB_AI_URL . 'assets/admin.css', [], PRESSHUB_AI_VERSION );
        wp_enqueue_script( 'presshub-ai-admin-js', PRESSHUB_AI_URL . 'assets/admin.js', [ 'jquery' ], PRESSHUB_AI_VERSION, true );
        
        wp_localize_script( 'presshub-ai-admin-js', 'presshubAI', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'presshub_ai_nonce' )
        ] );
    }

    public function add_coauthor_metabox() {
        add_meta_box(
            'presshub_ai_coauthor',
            'AI Co-Author & Editorial Review',
            [ $this, 'render_metabox' ],
            'post',
            'normal',
            'high'
        );
    }

    public function render_metabox( $post ) {
        $scorecard = get_post_meta( $post->ID, '_presshub_ai_scorecard', true );
        ?>
        <div class="presshub-ai-container">
            <h3>Multi-Modal Source Material</h3>
            <p class="description">Attach research files (PDF, DOCX, MP3, MP4) or paste URLs/notes.</p>
            <input type="file" id="presshub-ai-files" multiple accept=".pdf,.docx,.mp3,.mp4,.wav,.m4a" style="margin-bottom: 10px; display: block;" />
            <textarea id="presshub-ai-sources" rows="4" style="width:100%;" placeholder="Paste notes or URLs here..."></textarea>
            
            <h3>Journalist Instructions</h3>
            <p class="description">What should the AI focus on in this draft?</p>
            <textarea id="presshub-ai-instructions" rows="2" style="width:100%;"></textarea>
            
            <button type="button" id="presshub-ai-generate-draft" class="button button-primary" data-post-id="<?php echo esc_attr( $post->ID ); ?>" style="margin-top: 10px;">
                Generate Initial Draft
            </button>
            <span id="presshub-ai-draft-spinner" class="spinner"></span>
            
            <hr />
            
            <h3>Editorial Scorecard</h3>
            <button type="button" id="presshub-ai-run-review" class="button" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                Run AI Editorial Review
            </button>
            <span id="presshub-ai-review-spinner" class="spinner"></span>
            
            <div id="presshub-ai-scorecard-results" style="margin-top: 15px;">
                <?php if ( $scorecard ) : ?>
                    <div class="scorecard-box">
                        <strong>Score: <?php echo esc_html( $scorecard['score'] ); ?>/100</strong>
                        <p><?php echo esc_html( $scorecard['feedback'] ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
