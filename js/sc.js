jQuery(function() {
    var billing_country_first_val = jQuery("#billing_country").val();
    
    jQuery("#billing_country").change(function() {
        if(jQuery("#billing_country").val() != billing_country_first_val) {
            console.log('change')
            console.log(jQuery("#billing_country").val())
            
            billing_country_first_val = jQuery("#billing_country").val();
        
            jQuery(document).ready(function($) {
                var data = {
                    action: 'get_APMS',
                    country: jQuery("#billing_country").val(),
                //    token: myAjax.token,
                //    t: myAjax.t
                };

                jQuery.post(myAjax.ajaxurl, data, function(response) {
                    //
                });
            });
        }
    });
});
