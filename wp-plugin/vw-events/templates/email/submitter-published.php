<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<h2>Dein Event ist online!</h2>
<p><strong><?php echo esc_html( $title ); ?></strong> wurde von der Verwaltung freigegeben und ist nun öffentlich sichtbar.</p>
<p><a href="<?php echo esc_url( $permalink ); ?>">Event ansehen →</a></p>
<p style="color:#666;font-size:12px;"><?php echo esc_html( $site_name ); ?> · <?php echo esc_url( $site_url ); ?></p>
