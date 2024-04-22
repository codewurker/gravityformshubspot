/* global gform_hubspot_pluginsettings_strings */
window.GFHubSpotSettings = null;

(function ($) {
    GFHubSpotSettings = function () {
        var self = this;

        this.init = function () {
            this.pageURL = gform_hubspot_pluginsettings_strings.settings_url;

            this.deauthActionable = false;

            this.bindDeauthorize();
            this.bindClearCache();
        }

        this.bindDeauthorize = function () {
            // De-Authorize Zoho CRM.
            $('.gform_hubspot_deauth_button').on('click', function (e) {
                e.preventDefault();

                // Get button.
                var deauthButton = $('#gform_hubspot_deauth_button'),
                    deauthScope = $('#deauth_scope'),
                    disconnectMessage = gform_hubspot_pluginsettings_strings.disconnect;

                if (!self.deauthActionable) {
                    $('.gform_hubspot_deauth_button').eq(0).hide();

                    deauthScope.show(0, function(){
                        self.deauthActionable = true;
                    });
                } else {
                    var deauthScopeVal = $('#deauth_scope0').is(':checked') ? 'site' : 'account';
                    // Confirm deletion.
                    if (!confirm(disconnectMessage[deauthScopeVal])) {
                        return false;
                    }

                    // Set disabled state.
                    deauthButton.attr('disabled', 'disabled');

                    // De-Authorize.
                    $.ajax({
                        async: false,
                        url: ajaxurl,
                        dataType: 'json',
                        method: 'POST',
                        data: {action: 'gfhubspot_deauthorize', scope: deauthScopeVal, nonce: gform_hubspot_pluginsettings_strings.deauth_nonce},
                        success: function (response) {
                            if (response.success) {
                                window.location.href = self.pageURL;
                            } else {
                                alert(response.data.message);
                            }

                            deauthButton.removeAttr('disabled');
                        }
                    }).fail(function(jqXHR, textStatus, error) {
                        alert(error);
                        deauthButton.removeAttr('disabled');
                    });
                }

            });
        }

        this.bindClearCache = function() {
			$( '#clear_hubspot_cache' ).on( 'click', function ( e ) {

				e.preventDefault();
				e.stopImmediatePropagation();

				var $button = $( this );
				$button.attr( 'disabled', true );

				$.ajax({
					method: 'POST',
					url: ajaxurl,
					data: {
						action: 'gf_hubspot_clear_cache',
						nonce: gform_hubspot_pluginsettings_strings.clear_cache_nonce,
					},
					success: function( response ) {
						if ( 'last_clearance' in response.data ) {
							jQuery('.success-alert-container').fadeIn();
							$( '#last_cache_clearance .time' ).text( response.data.last_clearance );
						}
					},
					error: function () {
							jQuery('.error-alert-container').fadeIn();
					},
					complete: function () {
						$button.attr( 'disabled', false );
						setTimeout( function () { jQuery('.alert-container').fadeOut(); }, 10000 );
					}
				});
			});
		};

        this.init();
    };

    $(document).ready( GFHubSpotSettings );
})(jQuery);
