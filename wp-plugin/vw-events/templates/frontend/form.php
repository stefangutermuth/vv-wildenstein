<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * @var WP_Term[] $standorte
 * @var WP_Term[] $categories
 * @var string    $turnstile_site
 */
?>
<form class="vw-events-form" novalidate>
    <div class="vw-row">
        <label for="vw-title"><?php esc_html_e( 'Titel', 'vw-events' ); ?> *</label>
        <input type="text" id="vw-title" name="title" maxlength="200" required>
        <span class="vw-error" data-for="title"></span>
    </div>

    <div class="vw-row">
        <label for="vw-description"><?php esc_html_e( 'Beschreibung', 'vw-events' ); ?> *</label>
        <textarea id="vw-description" name="description" rows="6" maxlength="8000" required></textarea>
        <span class="vw-error" data-for="description"></span>
    </div>

    <div class="vw-row vw-grid-2">
        <div>
            <label for="vw-start"><?php esc_html_e( 'Start', 'vw-events' ); ?> *</label>
            <input type="datetime-local" id="vw-start" name="start" required>
            <span class="vw-error" data-for="start"></span>
        </div>
        <div>
            <label for="vw-end"><?php esc_html_e( 'Ende', 'vw-events' ); ?></label>
            <input type="datetime-local" id="vw-end" name="end">
            <span class="vw-error" data-for="end"></span>
        </div>
    </div>

    <div class="vw-row">
        <label><input type="checkbox" name="all_day" value="1"> <?php esc_html_e( 'Ganztägig', 'vw-events' ); ?></label>
    </div>

    <div class="vw-row">
        <label for="vw-location-name"><?php esc_html_e( 'Ort-Name', 'vw-events' ); ?></label>
        <input type="text" id="vw-location-name" name="location_name">
    </div>
    <div class="vw-row">
        <label for="vw-location-addr"><?php esc_html_e( 'Adresse', 'vw-events' ); ?></label>
        <textarea id="vw-location-addr" name="location_addr" rows="3"></textarea>
    </div>

    <fieldset class="vw-row">
        <legend><?php esc_html_e( 'Standort(e)', 'vw-events' ); ?> *</legend>
        <?php if ( is_array( $standorte ) ) : foreach ( $standorte as $t ) : ?>
            <label class="vw-check"><input type="checkbox" name="standort[]" value="<?php echo esc_attr( $t->slug ); ?>"> <?php echo esc_html( $t->name ); ?></label>
        <?php endforeach; endif; ?>
        <span class="vw-error" data-for="standort"></span>
    </fieldset>

    <div class="vw-row">
        <label for="vw-category"><?php esc_html_e( 'Kategorie', 'vw-events' ); ?></label>
        <select id="vw-category" name="category[]">
            <option value=""><?php esc_html_e( '— bitte wählen —', 'vw-events' ); ?></option>
            <?php if ( is_array( $categories ) ) : foreach ( $categories as $t ) : ?>
                <option value="<?php echo esc_attr( $t->slug ); ?>"><?php echo esc_html( $t->name ); ?></option>
            <?php endforeach; endif; ?>
        </select>
    </div>

    <div class="vw-row vw-grid-2">
        <div>
            <label for="vw-organizer-name"><?php esc_html_e( 'Veranstalter', 'vw-events' ); ?> *</label>
            <input type="text" id="vw-organizer-name" name="organizer_name" required>
            <span class="vw-error" data-for="organizer_name"></span>
        </div>
        <div>
            <label for="vw-organizer-email"><?php esc_html_e( 'Veranstalter-E-Mail', 'vw-events' ); ?> *</label>
            <input type="email" id="vw-organizer-email" name="organizer_email" required>
            <span class="vw-error" data-for="organizer_email"></span>
        </div>
    </div>

    <div class="vw-row">
        <label for="vw-url"><?php esc_html_e( 'Veranstalter-Website / Event-Link', 'vw-events' ); ?></label>
        <input type="url" id="vw-url" name="url">
        <span class="vw-error" data-for="url"></span>
    </div>

    <div class="vw-row vw-grid-2">
        <div>
            <label for="vw-submitter-name"><?php esc_html_e( 'Dein Name', 'vw-events' ); ?> *</label>
            <input type="text" id="vw-submitter-name" name="submitter_name" required>
            <span class="vw-error" data-for="submitter_name"></span>
        </div>
        <div>
            <label for="vw-submitter-email"><?php esc_html_e( 'Deine E-Mail', 'vw-events' ); ?> *</label>
            <input type="email" id="vw-submitter-email" name="submitter_email" required>
            <span class="vw-error" data-for="submitter_email"></span>
        </div>
    </div>

    <div class="vw-row">
        <label for="vw-image"><?php esc_html_e( 'Plakat / Bild', 'vw-events' ); ?></label>
        <input type="file" id="vw-image" name="image" accept="image/jpeg,image/png,image/webp">
        <p class="description"><?php esc_html_e( 'max. 10 MB, JPEG / PNG / WebP', 'vw-events' ); ?></p>
        <div class="vw-image-preview"></div>
        <span class="vw-error" data-for="image"></span>
    </div>

    <p class="vw-notice"><?php esc_html_e( 'Dein Event wird vor Veröffentlichung von der Verwaltung geprüft.', 'vw-events' ); ?></p>

    <?php if ( $turnstile_site !== '' ) : ?>
        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site ); ?>"></div>
        <span class="vw-error" data-for="turnstile_token"></span>
    <?php else : ?>
        <p class="vw-error"><?php esc_html_e( 'Bot-Schutz nicht konfiguriert — Formular ist deaktiviert.', 'vw-events' ); ?></p>
    <?php endif; ?>

    <input type="text" name="website_url" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">

    <div class="vw-row">
        <button type="submit" class="vw-submit"<?php echo $turnstile_site === '' ? ' disabled' : ''; ?>><?php esc_html_e( 'Event einreichen', 'vw-events' ); ?></button>
    </div>

    <div class="vw-form-message" hidden></div>
</form>
