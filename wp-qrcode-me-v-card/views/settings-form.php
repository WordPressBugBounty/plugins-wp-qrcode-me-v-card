<?php
defined( 'ABSPATH' ) || exit;

/* @var $wqm_type string */
/* @var $wqm_visual_style string */
/* @var $wqm_margin string */
/* @var $wqm_correction_level string */
/* @var $wqm_label string */
/* @var $wqm_logo_id int */
/* @var $wqm_logo_width string */
/* @var $wqm_logo_height string */
/* @var $wqm_bgcolor array */
/* @var $wqm_fgcolor array */
/* @var $wqm_filename string */
/* @var $wqm_size string */

if ( empty( $wqm_margin ) ) {
	$wqm_margin = 10;
}
if ( empty( $wqm_size ) ) {
	$wqm_size = 400;
}

$wqm_logo_path_url = '';
if ( ! empty( $wqm_logo_id ) ) {
	$wqm_logo_path_url = wp_get_attachment_image_url( $wqm_logo_id, array( 100, 100 ) );
}

//todo: выводить у пресетов их размеры или все что на них навесили
?>

<table class="form-table" role="presentation">
    <tbody>
    <tr class="field-type">
        <th><label for="field-type"><?php _e( 'Type', 'wp-qrcode-me-v-card' ) ?></label></th>
        <td>
            <select name="wqm_type" id="field-type">
                <option value="vcard" <?php echo selected( 'vcard', $wqm_type ) ?>>vCard v3</option>
                <option value="mecard" <?php echo selected( 'mecard', $wqm_type ) ?>>MeCard</option>
            </select>
            <span class="description"><?php _e( 'Select contact information QR code format', 'wp-qrcode-me-v-card' ) ?></span>
            <span class="description"><?php _e( 'MeCard has encoding issues and support less fields', 'wp-qrcode-me-v-card' ) ?></span>
        </td>
    </tr>
    <tr class="field-qr-visual-style">
        <th><label for="field-qr-visual-style"><?php _e( 'QR appearance', 'wp-qrcode-me-v-card' ) ?></label></th>
        <td>
			<?php
			$wqm_visual_current = isset( $wqm_visual_style ) ? sanitize_key( strval( $wqm_visual_style ) ) : 'square';
			if ( '' === $wqm_visual_current || ! array_key_exists( $wqm_visual_current, WQM_QR_Code_Type::get_visual_style_options() ) ) {
				$wqm_visual_current = 'square';
			}
			?>
            <div class="wqm-qr-visual-select-wrap">
            <select name="wqm_visual_style" id="field-qr-visual-style" class="wqm-visual-style-select">
				<?php foreach ( WQM_QR_Code_Type::get_visual_style_options() as $slug => $def ) : ?>
                    <option
						value="<?php echo esc_attr( $slug ); ?>"
						<?php echo selected( $wqm_visual_current, $slug, false ); ?>
						data-preview-url="<?php echo esc_url( $def['preview'] ); ?>"><?php echo esc_html( $def['label'] ); ?></option>
				<?php endforeach; ?>
            </select>
            </div>
            <span class="description"><?php _e( 'How dark modules are drawn (PNG export). Decorative styles need a high error correction level and testing with real scanners.', 'wp-qrcode-me-v-card' ) ?></span>
        </td>
    </tr>
    <tr class="field-margin">
        <th><label for="field-margin"><?php _e( 'Margin', 'wp-qrcode-me-v-card' ) ?></label></th>
        <td>
            <input type="text" name="wqm_margin" id="field-margin" class="regular-text" placeholder="10"
                   value="<?php echo WQM_Common::clear_digits( esc_attr( $wqm_margin ) ); ?>">
            <span class="description"><?php _e( 'Specify border size around QR code in px', 'wp-qrcode-me-v-card' ) ?></span>
        </td>
    </tr>
    <tr class="field-size">
        <th><label for="field-size"><?php _e( 'QR size', 'wp-qrcode-me-v-card' ) ?></label></th>
        <td>
            <?php
            // Default presets (px)
            $default_presets = array(400, 600, 800, 1000, 1200, 1600, 2000);
            // Allow 3rd-parties to modify or extend presets. Provide context via $post_id.
            $size_presets = apply_filters('wqm_qr_size_presets', $default_presets, $post_id ?? 0 );
            if (!is_array($size_presets) || empty($size_presets)) {
                $size_presets = $default_presets;
            }
            ?>
            <select name="wqm_size" id="field-size">
                <?php foreach ($size_presets as $preset):
                    $val = intval($preset);
                    if ($val < 100) { $val = 100; }
                    if ($val > 4096) { $val = 4096; }
                    ?>
                    <option value="<?php echo esc_attr($val); ?>" <?php selected(intval($wqm_size), $val); ?>><?php echo esc_html($val . ' × ' . $val . ' px'); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="description"><?php _e( 'Choose how large the QR image should be — it is always square (width equals height). Size is set in pixels.', 'wp-qrcode-me-v-card' ) ?></span>
        </td>
    </tr>
    <tr class="field-correction-level">
        <th>
            <label for="field-correction-level"><?php _e( 'Correction level', 'wp-qrcode-me-v-card' ) ?></label>
        </th>
        <td>
            <select name="wqm_correction_level" id="field-correction-level">
                <option value="LOW" <?php echo selected( 'LOW', $wqm_correction_level ) ?>>
					<?php _e( 'Level L – up to 7% damage', 'wp-qrcode-me-v-card' ) ?>
                </option>
                <option value="MEDIUM" <?php echo selected( 'MEDIUM', $wqm_correction_level ) ?>>
					<?php _e( 'Level M – up to 15% damage', 'wp-qrcode-me-v-card' ) ?>
                </option>
                <option value="QUARTILE" <?php echo selected( 'QUARTILE', $wqm_correction_level ) ?>>
					<?php _e( 'Level Q – up to 25% damage', 'wp-qrcode-me-v-card' ) ?>
                </option>
                <option value="HIGH" <?php echo selected( 'HIGH', $wqm_correction_level ) ?>>
					<?php _e( 'Level H – up to 30% damage', 'wp-qrcode-me-v-card' ) ?>
                </option>
            </select>
            <span class="description"><?php _e( 'There are different amounts of “backup” data depending on how much damage the QR code is expected to suffer in its intended environment.', 'wp-qrcode-me-v-card' ) ?></span>
        </td>
    </tr>
    <tr class="field-label">
        <th><label for="field-label"><?php _e( 'Label', 'wp-qrcode-me-v-card' ) ?></label></th>
        <td>
            <input type="text" name="wqm_label" id="field-label" class="regular-text"
                   value="<?php esc_attr_e( $wqm_label ); ?>">
            <span class="description"><?php _e( 'Optional text label below QR code.', 'wp-qrcode-me-v-card' ) ?></span>
        </td>
    </tr>
    <tr class="field-logo">
        <th><label for="field-logo"><?php _e( 'Logo', 'wp-qrcode-me-v-card' ) ?></label></th>
        <td>
            <input id="field-logo" name="wqm_logo_id" type="hidden" value="<?php echo $wqm_logo_id; ?>">
            <img src="<?php echo $wqm_logo_path_url; ?>" id="wqm-picsrc" alt=""/>
            <button type="button" id="wqm_logo_path_upload" class="button button-primary button-large"
                    style="<?php echo ! empty( $wqm_logo_id ) ? 'display:none;' : '' ?>">
				<?php _e( 'Select logo image', 'wp-qrcode-me-v-card' ) ?></button>
            <button type="button" id="wqm_logo_path_delete" class="button button-add-media button-large"
                    style="<?php echo ! empty( $wqm_logo_id ) ? '' : 'display:none;' ?>">
				<?php _e( 'Delete logo image', 'wp-qrcode-me-v-card' ) ?></button>
            <span class="description"><?php _e( 'Optional logo image at center of QR code.', 'wp-qrcode-me-v-card' ) ?></span>
        </td>
    </tr>
    <tr class="field-label-size">
        <th><label for="field-label-size"><?php _e( 'Logo size', 'wp-qrcode-me-v-card' ) ?></label></th>
        <td>
            <div class="logo-sizes">
                <input type="text" name="wqm_logo_width" id="field-logo-size-width"
                       placeholder="<?php _e( 'width', 'wp-qrcode-me-v-card' ) ?>"
                       value="<?php esc_attr_e( $wqm_logo_width ); ?>">
                <input type="text" name="wqm_logo_height" id="field-logo-size-height"
                       placeholder="<?php _e( 'height', 'wp-qrcode-me-v-card' ) ?>"
                       value="<?php esc_attr_e( $wqm_logo_height ); ?>">
            </div>
            <span class="description"><?php _e( 'Set logo image size in pixels or percents.', 'wp-qrcode-me-v-card' ) ?></span>
        </td>
    </tr>
    <tr class="field-label-bgcolor">
        <th><label for="field-label-bgcolor"><?php _e( 'Background color', 'wp-qrcode-me-v-card' ) ?></label></th>
        <td>
            <div class="field-bgcolors">
                <input type="text" name="wqm_bgcolor" id="field-bgcolor" class="wqm-color-picker" data-alpha-enabled="true" value="<?php esc_attr_e( $wqm_bgcolor ); ?>">
            </div>
            <span class="description"><?php _e( 'Set qrcode background color in RGBA format.', 'wp-qrcode-me-v-card' ) ?></span>
        </td>
    </tr>
    <tr class="field-label-fgcolor">
        <th><label for="field-label-fgcolor"><?php _e( 'Text & code color', 'wp-qrcode-me-v-card' ) ?></label></th>
        <td>
            <div class="field-fgcolors">
                <input type="text" name="wqm_fgcolor" id="field-fgcolor" class="wqm-color-picker" data-alpha-enabled="false" value="<?php esc_attr_e( $wqm_fgcolor ); ?>">
            </div>
            <span class="description"><?php _e( 'Set qrcode background color in RGB format.', 'wp-qrcode-me-v-card' ) ?></span>
        </td>
    </tr>
    <tr class="warn-message" <?php echo ( ! empty( $wqm_fgcolor ) or ! empty( $wqm_bgcolor ) ) ? '' : 'style="display:none;"' ?>>
        <th></th>
        <td>
            <div class="please-warn"><p><?php _e( 'Please! Make sure that you colored qr-code can be read by scanner!!', 'wp-qrcode-me-v-card' ) ?></p></div>
        </td>
    </tr>
    <tr class="field-filename">
        <th><label for="field-filename"><?php _e( 'File name', 'wp-qrcode-me-v-card' ) ?></label></th>
        <td>
            <input type="text" name="wqm_filename" id="field-filename" class="regular-text" value="<?php esc_attr_e( $wqm_filename ); ?>">
            <span class="description"><?php _e( 'File name when enable save/open v-card as file in widget. Enabled patterns: ', 'wp-qrcode-me-v-card' ) ?><span class="patrn">%_name_%</span>, <span class="patrn">%_nickname_%</span>, <span class="patrn">%_title_%</span>, <span class="patrn">%_organization_%</span></span>
        </td>
    </tr>
    </tbody>
</table>

<script>
    jQuery(document).ready(function () {
        jQuery('#wqm_logo_path_delete').click(function () {
            jQuery('#field-logo').val('');
            jQuery('#wqm-picsrc').prop('src', '');
            jQuery('#field-logo-size-width').val('');
            jQuery('#field-logo-size-height').val('');
            jQuery('#wqm_logo_path_upload').show();
            jQuery('#wqm_logo_path_delete').hide();
        });

        jQuery('.field-bgcolors, .field-fgcolors').click(function () {
            jQuery('.warn-message').show();
        });

        jQuery('#wqm_logo_path_upload').click(function (e) {
            e.preventDefault();

            // Проверка наличия wp.media и версии
            if (!wp || !wp.media || (typeof wp.media.view.MediaFrame === 'undefined' && typeof wp.media.frame === 'undefined')) {
                console.error('wp.media не найден либо находится в неподдерживаемой версии.');
                return;
            }

            var mediaFrame;

            // Используем старую или новую структуру MediaFrame
            if (typeof wp.media.view.MediaFrame.Select !== 'undefined') {
                mediaFrame = new wp.media.view.MediaFrame.Select({
                    title: '<?php _e( 'Select logo image', 'wp-qrcode-me-v-card' ); ?>',
                    multiple: false,
                    library: {
                        order: 'ASC',
                        orderby: 'title',
                        type: 'image',
                        search: null,
                        uploadedTo: null
                    },
                    button: {
                        text: '<?php _e( 'Select logo image', 'wp-qrcode-me-v-card' ); ?>'
                    }
                });
            } else {
                mediaFrame = wp.media({
                    title: '<?php _e( 'Select logo image', 'wp-qrcode-me-v-card' ); ?>',
                    multiple: false,
                    library: {
                        type: 'image'
                    },
                    button: {
                        text: '<?php _e( 'Select logo image', 'wp-qrcode-me-v-card' ); ?>'
                    }
                });
            }

            // Open the modal.
            mediaFrame.open();

            mediaFrame.on('select', function () {
                var mediaFrameProps;

                if (typeof mediaFrame.state().get('selection').first().toJSON === 'function') {
                    mediaFrameProps = mediaFrame.state().get('selection').first().toJSON();
                } else {
                    mediaFrameProps = mediaFrame.state().get('selection').first();
                }

                console.log(mediaFrameProps);
                jQuery('#field-logo').val(mediaFrameProps.id);
                jQuery('#wqm-picsrc').prop('src', mediaFrameProps.url);
                jQuery('#wqm_logo_path_upload').hide();
                jQuery('#wqm_logo_path_delete').show();

                return false;
            });
        }); // End on click

        /** Список: чуть крупнее превью + подпись */
        function wqmQrVisualDropdown(state) {
            if (!state.id) {
                return state.text;
            }
            var $opt = jQuery(state.element),
                url = $opt.attr('data-preview-url'),
                $row = jQuery('<span class="wqm-qr-visual-opt wqm-qr-visual-opt--dropdown"></span>');
            if (url) {
                $row.append(jQuery('<img>', { src: url, alt: '', width: 40, height: 40, loading: 'lazy' }));
            }
            $row.append(jQuery('<span class="wqm-qr-visual-label"></span>').text(state.text));
            return $row;
        }

        /** Свёрнутый селект: маленое превью + обрезанный текст, фикс. высота под стрелку */
        function wqmQrVisualSelected(state) {
            if (!state.id) {
                return state.text;
            }
            var $opt = jQuery(state.element),
                url = $opt.attr('data-preview-url'),
                $row = jQuery('<span class="wqm-qr-visual-opt wqm-qr-visual-opt--selection"></span>').attr('title', state.text);
            if (url) {
                $row.append(jQuery('<img>', { src: url, alt: '', width: 32, height: 32, loading: 'lazy' }));
            }
            $row.append(jQuery('<span class="wqm-qr-visual-label"></span>').text(state.text));
            return $row;
        }

        if (typeof jQuery.fn.select2 === 'function') {
            jQuery('#field-qr-visual-style').select2({
                width: '100%',
                templateResult: wqmQrVisualDropdown,
                templateSelection: wqmQrVisualSelected,
                minimumResultsForSearch: Infinity
            });
        }

        jQuery('.field-filename .patrn').click(function () {
            const element = jQuery('#field-filename');
            const caretPos = element[0].selectionStart;
            const textAreaTxt = element.val();

            element.val(textAreaTxt.substring(0, caretPos) + jQuery(this).html() + textAreaTxt.substring(caretPos) );
        })
    });
</script>