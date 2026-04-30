<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<h2>Hinweis zu deinem eingereichten Event</h2>
<p>Dein Event <strong><?php echo esc_html( $title ); ?></strong> konnte leider nicht veröffentlicht werden.</p>
<?php if ( ! empty( $reason ) ) : ?>
    <p><strong>Begründung:</strong></p>
    <blockquote><?php echo esc_html( $reason ); ?></blockquote>
<?php endif; ?>
<p>Bei Rückfragen melde dich gerne bei uns.</p>
<p style="color:#666;font-size:12px;"><?php echo esc_html( $site_name ); ?> · <?php echo esc_url( $site_url ); ?></p>
