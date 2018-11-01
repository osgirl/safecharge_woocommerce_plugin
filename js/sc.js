var isAjaxCalled = false;
var manualChangedCountry = false;
var tokAPMs = ['cc_card', 'paydotcom'];
var tokAPMsFields = {
    cardNumber: 'ccCardNumber'
    ,expirationMonth: 'ccExpMonth'
    ,expirationYear: 'ccExpYear'
    ,cardHolderName: 'ccNameOnCard'
    ,CVV: ''
};

 /**
  * Function validateScAPMsModal
  * When click save on modal, check for mandatory fields and validate them.
  */
 function scValidateAPMFields() {
    var formValid = true;
    var selectedPM = '';
     
    if(jQuery('li.apm_container').length > 0) {
        jQuery('li.apm_container').each(function(){
            var self = jQuery(this);
            var radioBtn = self.find('input.sc_payment_method_field');
            
            if(radioBtn.is(':checked')) {
                var apmField = self.find('.apm_field input');
                selectedPM = radioBtn.val();
                
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
        // check for method needed tokenization
        if(tokAPMs.indexOf(selectedPM) > -1) {
            var payload = {
                merchantSiteId: '',
            //    environment:    '',
                sessionToken:   '',
                billingAddress: {
                    city:       jQuery('#billing_city').val(),
                    country:    jQuery("#billing_country").val(),
                    zip:        jQuery("#billing_postcode").val(),
                    email:      jQuery("#billing_email").val(),
                    firstName:  jQuery("#billing_first_name").val(),
                    lastName:   jQuery("#billing_last_name").val(),
                //    state:      jQuery("#billing_state").val()
                },
                cardData: {
                    cardNumber:         jQuery('#' + selectedPM + '_' + tokAPMsFields.cardNumber).val(),
                    cardHolderName:     jQuery('#' + selectedPM + '_' + tokAPMsFields.cardHolderName).val(),
                    expirationMonth:    jQuery('#' + selectedPM + '_' + tokAPMsFields.expirationMonth).val().toString(),
                    expirationYear:     jQuery('#' + selectedPM + '_' + tokAPMsFields.expirationYear).val(),
                    CVV:                ''
                }
            };
            
            // call rest api to get first 3 parameters of payload
            jQuery.ajax({
                type: "POST",
                url: myAjax.ajaxurl,
                data: {
                    needST: 1
                    ,callFromJS: 1
                },
                dataType: 'json'
            })
                .done(function(resp){
                    console.log(resp)
                    if(resp.status == 1) {
                        payload.merchantSiteId = resp.data.merchantId;
                        payload.sessionToken = resp.data.sessionToken;
                        
                        if(resp.data.test == 'yes') {
                            payload.environment = 'test';
                        }
                    }
                    
                    console.log(payload)
                    
                    // get tokenization card number
                    if(typeof Safecharge != 'undefined') {
                        // call it like this:
                        Safecharge.card.createToken(payload, safechargeResultHandler);
                    }
                    else {
                        console.error('Safecharge Class not loaded. Reload the page and check the script!');
                    }
                });
        }
        else {
            jQuery('form.woocommerce-checkout').submit();
        }
    }
 }
 
 // handler for tokenization result
 function safechargeResultHandler(res) {
     console.log(res)
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
        console.log('billing_country change')
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
                            var pmMsg = '';
                            if(
                                pMethods[i]['paymentMethodDisplayName'].length > 0
                                && typeof pMethods[i]['paymentMethodDisplayName'][0].message != 'undefined'
                            ) {
                                pmMsg = pMethods[i]['paymentMethodDisplayName'][0].message;
                            }
                            
                            var newImg = pmMsg;
                            if(typeof pMethods[i]['logoURL'] != 'undefined') {
                                newImg = '<img src="'+ pMethods[i]['logoURL'].replace('/svg/', '/svg/solid-white/')
                                        +'" alt="'+ pmMsg +'">';
                            }
                            
                            html +=
                                '<li class="apm_container">'
                                    +'<div class="apm_title">'
                                        +newImg
                                        +'<input id="sc_payment_method_'+ pMethods[i].paymentMethod +'" type="radio" class="input-radio sc_payment_method_field" name="payment_method_sc" value="'+ pMethods[i].paymentMethod +'" />'
                                        +'<span class=""></span>'
                                    +'</div>'
                                    
                                    +'<div class="apm_fields">';
                            
                            // create fields for the APM
                            if(pMethods[i].fields.length > 0) {
                                for(var j in pMethods[i].fields) {
                                    var pattern = '';
                                    try {
                                        pattern = pMethods[i].fields[j].regex;
                                        if(pattern === undefined) {
                                            pattern = '';
                                        }
                                    }
                                    catch(e) {}

                                    var placeholder = '';
                                    try {
                                        placeholder = pMethods[i].fields[j].caption[0].message;
                                        if(placeholder === undefined) {
                                            placeholder = '';
                                        }
                                    }
                                    catch(e) {}
                                    
                                    var fieldErrorMsg = '';
                                    try {
                                        fieldErrorMsg = pMethods[i].fields[j].validationmessage[0].message;
                                        if(fieldErrorMsg === undefined) {
                                            fieldErrorMsg = '';
                                        }
                                    }
                                    catch(e) {}

                                    html +=
                                            '<div class="apm_field">'
                                                +'<input id="'+ pMethods[i].paymentMethod +'_'+ pMethods[i].fields[j].name +'" name="'+ pMethods[i].paymentMethod +'['+ pMethods[i].fields[j].name +']" type="'+ pMethods[i].fields[j].type +'" pattern="'+ pattern + '" placeholder="'+ placeholder +'" autocomplete="new-password" />';

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
