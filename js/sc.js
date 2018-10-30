var isAjaxCalled = false;
var manualChangedCountry = false;

 /**
  * Function validateScAPMsModal
  * When click save on modal, check for mandatory fields and validate them.
  */
 function scValidateAPMFields() {
    var formValid = true;
     
    if(jQuery('li.apm_container').length > 0) {
        jQuery('li.apm_container').each(function(){
            var self = jQuery(this);
            var radioBtn = self.find('input.sc_payment_method_field');
            
            if(radioBtn.is(':checked')) {
                var apmField = self.find('.apm_field input');
                
                if (
                    typeof apmField.attr('pattern') != 'undefined'
                    && apmField.attr('pattern') !== false
                    && apmField.attr('pattern') != ''
                ) {
                    var regex = new RegExp(apmField.attr('pattern'), "i");
                    
                    // SHOW error
                    if(regex.test(apmField.val()) == false || apmField.val() == '') {
                        apmField.parent('.apm_field').find('.apm_error')
                            .removeClass('error_info')
                            .show();
                    
                        formValid = false;
                    }
                    // HIDE error
                    else {
                        apmField.parent('.apm_field').find('.error').hide();
                    }
                }
            }
        });
    }
    
    if(formValid) {
        jQuery('form.woocommerce-checkout').submit();
    }
 }
 
 /**
  * Function showErrorLikeInfo
  * Show error message as information about the field.
  * 
  * @param {int} elemId
  */
 function showErrorLikeInfo(elemId) {
    jQuery('#error_'+elemId).addClass('error_info');

    if(jQuery('#error_'+elemId).css('display') == 'block') {
        jQuery('#error_'+elemId).hide();
    }
    else {
        jQuery('#error_'+elemId).show();
    }
 }
 
jQuery(function() {
    var billing_country_first_val = jQuery("#billing_country").val();
    
    jQuery("#billing_country").change(function() {
        if(jQuery("#billing_country").val() != billing_country_first_val) {
            manualChangedCountry = true;
            billing_country_first_val = jQuery("#billing_country").val();
        }
        
        if(isAjaxCalled === false || manualChangedCountry === true) {
            isAjaxCalled = true;
            
            jQuery.ajax({
                type: "POST",
                url: myAjax.ajaxurl,
                data: {
                    country: jQuery("#billing_country").val()
                    ,callFromJS: 1
                },
                dataType: 'json'
            })
                .done(function(resp){
                    if(
                        typeof resp != 'undefined'
                        && resp !== null
                        && resp.status == 1
                        && typeof resp.data['paymentMethods'] != 'undefined'
                        && resp.data['paymentMethods'].length > 0
                    ) {
                        var html = '';
                        var pMethods = resp.data['paymentMethods'];
                        
                        html +=
                            '<ul id="sc_apms_list">';

                        for(var i in pMethods) {
                            var newImg = pMethods[i]['logoURL'].replace('/svg/', '/svg/solid-white/');
                            var pmMsg = '';
                            
                            if(typeof pMethods[i]['paymentMethodDisplayName'][0]['message'] != 'undefined') {
                                pmMsg = pMethods[i]['paymentMethodDisplayName'][0]['message'];
                            }
                            
                            html +=
                                '<li class="apm_container">'
                                    +'<div class="apm_title">'
                                        +'<img src="'+ newImg +'" alt="'+ pmMsg +'">'
                                        +'<input id="sc_payment_method_'+ pMethods[i].paymentMethod +'" type="radio" class="input-radio sc_payment_method_field" name="payment_method_sc" value="'+ pMethods[i].paymentMethod +'" />'
                                        +'<span class=""></span>'
                                    +'</div>'
                                    
                                    +'<div class="apm_fields">';
                            
                            // create fields for the APM
                            for(var j in pMethods[i].fields) {
                                var pattern = '';
                                var fieldErrorMsg = '';

                                if(typeof pMethods[i].fields[j].regex != 'undefined') {
                                    pattern = 'pattern="'+ pMethods[i].fields[j].regex +'"';
                                }

                                html +=
                                        '<div class="apm_field">'
                                            +'<input id="'+ pMethods[i].fields[j].name +'" name="'+ pMethods[i].paymentMethod +'['+ pMethods[i].fields[j].name +']" type="'+ pMethods[i].fields[j].type +'" '+ pattern + ' placeholder="'+ pMethods[i].fields[j].caption[0].message +'" autocomplete="new-password" />';
                                    
                                if(pattern != '') {
                                    html +=
                                            '<span class="question_mark" onclick="showErrorLikeInfo(\'sc_'+ pMethods[i].fields[j].name +'\')"><span class="tooltip-icon"></span></span>'
                                            +'<div class="apm_error" id="error_sc_'+ pMethods[i].fields[j].name +'">'
                                                +'<label>'+fieldErrorMsg+'</label>'
                                            +'</div>';
                                }
                                
                                html +=
                                        '</div>';
                            }
                                
                            html +=
                                    '</div>'
                                +'</li>';
                        }
                        
                        html +=
                            '</ul>';
                        
                        // wait until checkout is updated
                        jQuery( document ).on( 'updated_checkout', function() {
                            // remove old apms
                            jQuery('#payment').find('.sc_apms').remove();
                            
                            if(html != '') {
                                // clean old APMs
                                if(jQuery('.payment_method_sc:last').find('ul#sc_apms_list').length > 0) {
                                    jQuery('.payment_method_sc:last').find('ul#sc_apms_list').remove();
                                }
                                
                                // insert the html
                                jQuery('.payment_method_sc:last').append(html);
                                jQuery('form[name="checkout"]').attr('onsubmit', "return false;");
                                
                                // change submit button type and behavior
                                jQuery('form.woocommerce-checkout button[type=submit]')
                                    .attr('type', 'button')
                                    .attr('onclick', 'scValidateAPMFields()');
                            }
                        });
                    }
                });
        }
    });
    
    // when click on APM payment method
    jQuery('form.woocommerce-checkout').on('click', '.apm_title', function() {
        // hide all check marks 
        jQuery('#sc_apms_list').find('.apm_title span').removeClass('apm_selected');
        
        // hide all containers with fields
        jQuery('#sc_apms_list').find('.apm_fields').each(function(){
            var self = jQuery(this);
            if(self.css('display') == 'block') {
                self.slideToggle('slow');
            }
        });
        
        // mark current payment method
        jQuery(this).find('span').addClass('apm_selected');
        
        // hide bottom border of apm_fields if the container is empty
        if(jQuery(this).parent('li').find('.apm_fields').html() == '') {
            jQuery(this).parent('li').find('.apm_fields').css('border-bottom', 0);
        }
        // expand payment fields
        if(jQuery(this).parent('li').find('.apm_fields').css('display') == 'none') {
            jQuery(this).parent('li').find('.apm_fields').slideToggle('slow');
        }
        
        // unchck SC payment methods
        jQuery('form.woocommerce-checkout').find('sc_payment_method_field').attr('checked', false);
        // check current radio
        jQuery(this).find('input').attr('checked', true);
        
        // hide errors
        jQuery('.apm_error').hide();
    });
});
// document ready function END
