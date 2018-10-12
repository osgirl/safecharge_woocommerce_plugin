var paymentMethods = {};
var isAjaxCalled = false;
var manualChangedCountry = false;

function scGenerateAPMFields(methodName) {
    jQuery('#apms_modal .modal-body').html('');

    if(typeof paymentMethods[methodName] == 'undefined') {
        return;
    }

    // do not show modal beacuse we do not have fileds in it
    if(paymentMethods[methodName].length == 0) {
        return;
    }

    var html = '';
    for(var idx in paymentMethods[methodName]) {
        var required = '';
        var pattern = '';
        var requiredStar = '';
        var fieldErrorMsg = '';

        if(typeof paymentMethods[methodName][idx].regex != 'undefined') {
            required = ' required="required"';
            pattern = 'pattern="'+ paymentMethods[methodName][idx].regex +'"';
            requiredStar = '&nbsp;<abbr class="required" title="required">*</abbr>';
        }
        
        if(typeof paymentMethods[methodName][idx].validationmessage != 'undefined') {
            fieldErrorMsg = 'data-valid-error-msg="'+paymentMethods[methodName][idx].validationmessage[0].message+'"';
        }

        html +=
            '<p class="form-row form-row-wide" id="'+ methodName +'_'+ paymentMethods[methodName][idx].name +'" data-o_class="form-row form-row-wide">'
                +'<label>'+ paymentMethods[methodName][idx].caption[0].message + requiredStar +'</label>'
                +'<span class="woocommerce-input-wrapper">'
                    +'<input class="input-text " name="apm_fields['+ paymentMethods[methodName][idx].name +']" type="'+ paymentMethods[methodName][idx].type +'" '+ required +' '+ pattern + ' '+ fieldErrorMsg +' data-apm="'+ methodName +'">'
                +'</span>'
            +'</p>';
    }

    jQuery('#apms_modal .modal-body').html(html);
    jQuery('#apms_modal').modal('toggle');
 }

 /**
  * Function validateScAPMsModal
  * When click save on modal, check for mandatory fields and validate them.
  */
 function scValidateAPMsModal() {
    var errorMsgTxt = '';
    var formValid = true;
    
    jQuery('#apms_modal').find('input').each(function(){
        var self = jQuery(this);
        
        if(self.prop('required') == true) {
            var regex = null;
            var apmFields = paymentMethods[self.attr('data-apm')];
            
            if(self.attr('pattern') != '') {
                regex = new RegExp(self.attr('pattern'), "i");
            }
            
            if(regex != null) {
                if(regex.test(self.val()) == false || self.val() == '') {
                    if(self.attr('data-valid-error-msg') != '') {
                        errorMsgTxt += self.attr('data-valid-error-msg') + "\n";
                    }

                    self.parents('p').addClass('woocommerce-invalid');
                    formValid = false;
                }
                else {
                    self.parents('p').removeClass('woocommerce-invalid');
                }
            }
        }
    });
    
    if(formValid == false) {
        if(errorMsgTxt != '') {
            alert(errorMsgTxt);
        }
    }
    else {
        jQuery('#apms_modal').modal('hide');
    }
 }
 
 // custom function to close the modal
 function closeACAPMsModal() {
    jQuery('input.sc_payment_method_field').each(function(){
        jQuery(this).prop('checked', false);
    });
    jQuery('#apms_modal').modal('toggle');
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
                        resp.status == 1
                        && typeof resp.data.paymentMethods != 'unknown'
                        && resp.data.paymentMethods.length > 0
                    ) {
                        var html = '';
                        var pMethods = resp.data.paymentMethods;

                        for(var i in pMethods) {
                            html +=
                                '<div style="paddin:10px 0px;" class="sc_apms">'
                                    +'<br/>'
                                    +'<label>'
                                        +'<input id="payment_method_'+ pMethods[i].paymentMethod +'" type="radio" class="input-radio sc_payment_method_field" name="payment_method_sc" value="'+ pMethods[i].paymentMethod +'" onchange="scGenerateAPMFields(this.value)" required="required" />&nbsp;&nbsp;'
                                        +pMethods[i]['paymentMethodDisplayName'][0]['message']+ ' '
                                        +'<img src="'+ pMethods[i]['logoURL'] +'" style="height:20px;">'
                                    +'</label>'
                                +'</div>';
                                

                            paymentMethods[pMethods[i].paymentMethod] = pMethods[i].fields;
                        }
                        
                        // wait until checkout is updated
                        jQuery( document ).on( 'updated_checkout', function() {
                            // remove old apms
                            jQuery('#payment').find('.sc_apms').remove();
                            
                            if(html != '') {
                                // insert the html
                                jQuery('.payment_method_sc:last').append(html);
                                jQuery('form[name="checkout"]').attr('onsubmit', "return false;");
                            }
                        });
                    }
                });
        }
    });
    
});
