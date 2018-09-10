jQuery(document).ready(function(){
    jQuery("#billing_country").change(function() {
            jQuery(document).ready(function($) {
                var data = {
                    action: 'get_APMS',
                    country: jQuery("#billing_country").val(),
                    token: myAjax.token,
                    t: myAjax.t
                };
                
                jQuery.post(myAjax.ajaxurl, data, function(response) {});
          });
    });
});
