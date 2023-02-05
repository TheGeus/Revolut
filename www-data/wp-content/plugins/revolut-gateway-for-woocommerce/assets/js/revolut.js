jQuery(function ($) {
  const PAYMENT_METHOD = {
    CreditCard: 'revolut_cc',
    RevolutPay: 'revolut_pay',
  }

  const CARD_WIDGET_TYPES = {
    CardField: 'card_field',
    Popup: 'popup',
  }

  let $body = $(document.body)
  let $form = $('form.woocommerce-checkout')
  let $order_review = $('#order_review')
  let $payment_save = $('form#add_payment_method')
  let instance = null
  let cardStatus = null
  let wc_order_id = 0
  let reload_checkout = 0
  const revolut_pay_v2 = $('.revolut-pay-v2').length > 0

  /**
   * Start processing
   */
  function startProcessing() {
    $.blockUI({
      message: null,
      overlayCSS: { background: '#fff', opacity: 0.6 },
    })
  }

  /**
   * Stop processing
   */
  function stopProcessing() {
    $.unblockUI()
    $('.blockUI.blockOverlay').hide()
  }

  /**
   * Handle status change
   * @param {string} status
   */
  function handleStatusChange(status) {
    cardStatus = status
  }

  /**
   * Handle validation
   * @param {array} errors
   */
  function handleValidation(errors) {
    let messages = errors
      .filter(item => item != null)
      .map(function (message) {
        return message
          .toString()
          .replace('RevolutCheckout: ', '')
          .replace('Validation: ', '')
      })

    displayRevolutError(messages)
  }

  /**
   * Handle error message
   * @param {string} message
   */
  function handleError(messages) {
    messages = messages.toString().split(',')
    const currentPaymentMethod = getPaymentMethod()

    messages = messages
      .filter(item => item != null)
      .map(function (message) {
        return message
          .toString()
          .replace('RevolutCheckout: ', '')
          .replace('Validation: ', '')
      })

    if (!messages.length || messages.length < 0 || !currentPaymentMethod) {
      return
    }

    let isChangePaymentMethodAddPage = $payment_save.length > 0

    if (isChangePaymentMethodAddPage) {
      displayRevolutError(messages)
    } else {
      handlePaymentResult(messages.join(' '))
    }
  }

  /**
   * Display error message
   * @param messages
   */
  function displayRevolutError(messages) {
    const currentPaymentMethod = getPaymentMethod()
    $('.revolut-error').remove()

    if (!messages.length || messages.length < 0 || !currentPaymentMethod) {
      return
    }

    let error_view =
      '<div class="revolut-error woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
      '<ul class="woocommerce-error">' +
      messages
        .map(function (message) {
          return '<li>' + message + '</li>'
        })
        .join('') +
      '</ul>' +
      '</div>'

    currentPaymentMethod.methodId === PAYMENT_METHOD.RevolutPay
      ? $('#woocommerce-revolut-pay-element').after(error_view)
      : $('#wc-revolut_cc-cc-form').after(error_view)

    stopProcessing()
  }

  /**
   * Handle cancel
   */
  function handleCancel() {
    stopProcessing()
  }

  /**
   * Check if we should save the selected Payment Method
   */
  function shouldSavePaymentMethod() {
    const currentPaymentMethod = $('input[name="payment_method"]:checked').val()
    let savePaymentDetails = 0

    let target = document.getElementById('woocommerce-revolut-card-element')

    if (currentPaymentMethod === PAYMENT_METHOD.CreditCard) {
      if ($('#wc-revolut_cc-new-payment-method').length) {
        savePaymentDetails = $('#wc-revolut_cc-new-payment-method:checked').length
      }

      if (target.dataset.paymentMethodSaveIsMandatory) {
        savePaymentDetails = 1
      }
    }

    return savePaymentDetails
  }

  /**
   * Get payment data
   * @param {Object} address
   * @return {{}}
   */
  function getPaymentData(nameRequired = true) {
    let address = getCheckoutFormData()

    let paymentData = {}

    if (nameRequired) {
      paymentData.name = address.billing_first_name + ' ' + address.billing_last_name
    }

    paymentData.email = address.billing_email
    paymentData.phone = address.billing_phone

    if (address.billing_country !== undefined && address.billing_postcode !== undefined) {
      paymentData.billingAddress = {
        countryCode: address.billing_country,
        region: address.billing_state,
        city: address.billing_city,
        streetLine1: address.billing_address_1,
        streetLine2: address.billing_address_2,
        postcode: address.billing_postcode,
      }
    }

    if (
      address.ship_to_different_address &&
      address.shipping_country !== undefined &&
      address.shipping_postcode !== undefined
    ) {
      paymentData.shippingAddress = {
        countryCode: address.shipping_country,
        region: address.shipping_state,
        city: address.shipping_city,
        streetLine1: address.shipping_address_1,
        streetLine2: address.shipping_address_2,
        postcode: address.shipping_postcode,
      }
    } else {
      if (paymentData.billingAddress !== undefined) {
        paymentData.shippingAddress = paymentData.billingAddress
      }
    }

    if (shouldSavePaymentMethod()) {
      let target = document.getElementById('woocommerce-revolut-card-element')
      paymentData.savePaymentMethodFor = target.dataset.savePaymentFor
    }

    return paymentData
  }

  function getAjaxURL(endpoint) {
    return wc_revolut.ajax_url
      .toString()
      .replace('%%wc_revolut_gateway_ajax_endpoint%%', `wc_revolut_${endpoint}`)
  }

  /**
   * Check if Revolut Payment options is selected
   */
  function isRevolutPaymentMethodSelected() {
    const currentPaymentMethod = $('input[name="payment_method"]:checked').val()
    return (
      currentPaymentMethod === PAYMENT_METHOD.CreditCard ||
      currentPaymentMethod === PAYMENT_METHOD.RevolutPay
    )
  }

  function isRevolutCardPaymentOptionSelected() {
    return $('#payment_method_revolut_cc').is(':checked')
  }

  function isRevolutPayPaymentOptionSelected() {
    return $('#payment_method_revolut_pay').is(':checked')
  }

  /**
   * Check if we should use the saved Payment Method
   */
  function payWithPaymentToken() {
    return (
      $('#wc-revolut_cc-payment-token-new:checked').length < 1 &&
      $('[id^="wc-revolut_cc-payment-token"]:checked').length > 0
    )
  }

  /**
   * Handle if success
   */
  function handlePaymentResult(errorMessage = '') {
    const currentPaymentMethod = getPaymentMethod()
    const savePaymentMethod = shouldSavePaymentMethod()
    const payment_token = $('input[name="wc-revolut_cc-payment-token"]:checked').val()
    const isCardPaymentSelected =
      currentPaymentMethod.methodId === PAYMENT_METHOD.CreditCard

    if (!wc_order_id && wc_revolut.order_id && $order_review.length > 0) {
      wc_order_id = wc_revolut.order_id
    }

    if (!isCardPaymentSelected) {
      startProcessing()
    }

    if (isPaymentMethodSaveView()) {
      return handlePaymentMethodSaveFrom(currentPaymentMethod)
    }

    let data = {}
    data['revolut_gateway'] = currentPaymentMethod.methodId
    data['revolut_public_id'] = currentPaymentMethod.publicId
    data['revolut_payment_error'] = errorMessage
    data['wc_order_id'] = wc_order_id
    data['reload_checkout'] = reload_checkout
    data['revolut_save_payment_method'] = isCardPaymentSelected ? savePaymentMethod : 0
    data['wc-revolut_cc-payment-token'] = isCardPaymentSelected ? payment_token : ''

    $.ajax({
      type: 'POST',
      dataType: 'json',
      url: getAjaxURL('process_payment_result'),
      data: data,
      success: processPaymentResultSuccess,
      error: function (jqXHR, textStatus, errorThrown) {
        if (jqXHR && jqXHR.responseText) {
          let response = jqXHR.responseText.match(/{(.*?)}/)
          if (response.length > 0 && response[0]) {
            try {
              response = JSON.parse(response[0])
              if (response.result && response.redirect) {
                return processPaymentResultSuccess(response)
              }
            } catch (e) {
              // swallow error and handle in generic block below
            }
          }
        }

        stopProcessing()
        displayWooCommerceError(`<div class="woocommerce-error">${errorThrown}</div>`)
      },
    })
  }

  function processPaymentResultSuccess(result) {
    if (result.result === 'success' && result.redirect) {
      window.location.href = result.redirect
      return true
    }

    if (reload_checkout) {
      window.location.reload()
      return
    }

    if (result.result === 'fail' || result.result === 'failure') {
      if (typeof result.messages == 'undefined') {
        result.messages = `<div class="woocommerce-error">${wc_checkout_params.i18n_checkout_error}</div>`
      }
      displayWooCommerceError(`<div class="woocommerce-error">${result.messages}</div>`)
      stopProcessing()
      return false
    }

    stopProcessing()
    displayWooCommerceError('<div class="woocommerce-error">Invalid response</div>')
  }

  function handlePaymentMethodSaveFrom(currentPaymentMethod) {
    $('.revolut_public_id').remove()
    $form = $payment_save
    if ($payment_save.length === 0) {
      $form = $order_review
    }
    $form.append(
      '<input type="hidden" class="revolut_public_id" name="revolut_public_id" value="' +
        currentPaymentMethod.publicId +
        '">',
    )
    $form.submit()
  }

  /**
   * Update widget
   */
  function handleUpdate() {
    const currentPaymentMethod = getPaymentMethod()
    if (instance !== null) {
      instance.destroy()
    }

    togglePlaceOrderButton()

    if (currentPaymentMethod.methodId !== PAYMENT_METHOD.RevolutPay) {
      if (
        currentPaymentMethod.widgetType != CARD_WIDGET_TYPES.Popup &&
        !$body.hasClass('woocommerce-order-pay')
      ) {
        instance = RevolutCheckout(currentPaymentMethod.publicId).createCardField({
          target: currentPaymentMethod.target,
          hidePostcodeField: !$body.hasClass('woocommerce-add-payment-method'),
          locale: currentPaymentMethod.locale,
          onCancel: handleCancel,
          onValidation: handleValidation,
          onStatusChange: handleStatusChange,
          onError: handleError,
          onSuccess: handlePaymentResult,
          styles: {
            default: {
              color: currentPaymentMethod.textcolor,
              '::placeholder': {
                color: currentPaymentMethod.textcolor,
              },
            },
          },
        })
      }

      if (currentPaymentMethod.hidePaymentMethod) {
        $('.payment_box.payment_method_revolut_cc').css({ height: 0, padding: 0 })
        $('.payment_box.payment_method_revolut_cc').children().css({ display: 'none' })
      }
    } else if (
      currentPaymentMethod.methodId === PAYMENT_METHOD.RevolutPay &&
      revolut_pay_v2
    ) {
      instance = RevolutCheckout.payments({
        locale: currentPaymentMethod.locale,
        publicToken: currentPaymentMethod.merchantPublicKey,
      })

      let address = getCheckoutFormData()

      const paymentOptions = {
        currency: currentPaymentMethod.currency,
        totalAmount: parseInt(currentPaymentMethod.total),
        validate: validateWooCommerceCheckout,
        createOrder: () => {
          return { publicId: currentPaymentMethod.publicId }
        },
        deliveryMethods: [
          {
            id: 'id',
            amount: currentPaymentMethod.shippingTotal,
            label: 'Shipping',
          },
        ],
        buttonStyle: {
          cashbackCurrency: currentPaymentMethod.currency,
          variant: revolut_pay_button_style.revolut_pay_button_theme,
          size: revolut_pay_button_style.revolut_pay_button_size,
          radius: revolut_pay_button_style.revolut_pay_button_radius,
        },
        customer: {
          name: address.billing_first_name,
          email: address.billing_email,
          phone: address.billing_phone,
        },
      }

      instance.revolutPay.mount(currentPaymentMethod.target, paymentOptions)

      instance.revolutPay.on('payment', function (event) {
        switch (event.type) {
          case 'success':
            handlePaymentResult()
            break
          case 'error':
            handleError([event.error.message].filter(Boolean))
            break
          case 'cancel':
            handleCancel()
            break
        }
      })
    } else if (
      currentPaymentMethod.methodId === PAYMENT_METHOD.RevolutPay &&
      !revolut_pay_v2
    ) {
      instance = RevolutCheckout(currentPaymentMethod.publicId).revolutPay({
        target: currentPaymentMethod.target,
        locale: currentPaymentMethod.locale,
        validate: validateWooCommerceCheckout,
        onCancel: handleCancel,
        onError: handleError,
        onSuccess: handlePaymentResult,
        buttonStyle: {
          variant: revolut_pay_button_style.revolut_pay_button_theme,
          size: revolut_pay_button_style.revolut_pay_button_size,
          radius: revolut_pay_button_style.revolut_pay_button_radius,
        },
      })
    }
  }

  /**
   * Initialize Revolut Card Popup widget
   */
  function showCardPopupWidget(billingInfo = false) {
    const currentPaymentMethod = getPaymentMethod()
    if (instance !== null) {
      instance.destroy()
    }

    paymentData = getPaymentData(false)

    if (billingInfo) {
      paymentData.email = billingInfo.email
      paymentData.phone = billingInfo.phone
      if (billingInfo.billingAddress) {
        paymentData.billingAddress = billingInfo.billingAddress
      }
    }

    instance = RevolutCheckout(currentPaymentMethod.publicId).payWithPopup({
      ...{
        locale: currentPaymentMethod.locale,
        onCancel: handleCancel,
        onError: handleError,
        onSuccess: handlePaymentResult,
      },
      ...paymentData,
    })
  }

  /**
   * Show validation errors
   * @param errorMessage
   */
  function displayWooCommerceError(errorMessage) {
    let payment_form = $form.length ? $form : $order_review

    $(
      '.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message',
    ).remove()
    payment_form.prepend(
      `<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">${errorMessage}</div>`,
    ) // eslint-disable-line max-len
    payment_form.removeClass('processing').unblock()
    payment_form.find('.input-text, select, input:checkbox').trigger('validate').blur()
    var scrollElement = $(
      '.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout',
    )
    if (!scrollElement.length) {
      scrollElement = $('.form.checkout')
    }
    $.scroll_to_notices(scrollElement)
    $(document.body).trigger('checkout_error')
  }

  /**
   * Submit credit card payment
   * @return {boolean}
   */
  function handleCreditCardSubmit() {
    if (!isRevolutCardPaymentOptionSelected()) {
      return true
    }

    const currentPaymentMethod = getPaymentMethod()

    if (
      !payWithPaymentToken() &&
      cardStatus != null &&
      !cardStatus.completed &&
      currentPaymentMethod.widgetType != CARD_WIDGET_TYPES.Popup
    ) {
      instance.validate()
      return false
    }

    startProcessing()

    submitWooCommerceOrder().then(function (valid) {
      if (valid) {
        if (payWithPaymentToken()) {
          handlePaymentResult()
          return false
        }

        if (currentPaymentMethod.widgetType == CARD_WIDGET_TYPES.Popup) {
          return showCardPopupWidget()
        }

        instance.submit(getPaymentData())
      }
    })

    return false
  }

  /**
   * Validate checkout form entries
   * @returns {Promise(boolean)}
   */
  function submitWooCommerceOrder() {
    return new Promise(function (resolve, reject) {
      if ($body.hasClass('woocommerce-order-pay')) {
        resolve(true)
        return
      }

      const currentPaymentMethod = getPaymentMethod()

      $.ajax({
        type: 'POST',
        url: wc_checkout_params.checkout_url,
        data:
          $form.serialize() +
          '&revolut_create_wc_order=1&revolut_public_id=' +
          currentPaymentMethod.publicId,
        dataType: 'json',
        success: function (result) {
          processWooCommerceOrderSubmissionSuccess(result, resolve)
        },
        error: function (jqXHR, textStatus, errorThrown) {
          if (jqXHR && jqXHR.responseText) {
            let response = jqXHR.responseText.match(/{(.*?)}/)
            if (response.length > 0 && response[0]) {
              try {
                response = JSON.parse(response[0])
                if (response.result) {
                  return processWooCommerceOrderSubmissionSuccess(response, resolve)
                }
              } catch (e) {
                // swallow error and handle in generic block below
              }
            }
          }

          stopProcessing()
          resolve(false)
          displayWooCommerceError(`<div class="woocommerce-error">${errorThrown}</div>`)
        },
      })
    })
  }

  function processWooCommerceOrderSubmissionSuccess(orderSubmission, resolve) {
    reload_checkout = orderSubmission.reload === true ? 1 : 0

    if (orderSubmission.result === 'revolut_wc_order_created') {
      if (orderSubmission['refresh-checkout'] || !orderSubmission['wc_order_id']) {
        startProcessing()
        window.location.reload()
        return resolve(false)
      }

      wc_order_id = orderSubmission['wc_order_id']
      return resolve(true)
    }

    if (orderSubmission.result === 'fail' || orderSubmission.result === 'failure') {
      stopProcessing()
      resolve(false)

      if (!orderSubmission.messages) {
        orderSubmission.messages = `<div class="woocommerce-error">${wc_checkout_params.i18n_checkout_error}</div>`
      }

      displayWooCommerceError(orderSubmission.messages)
      return false
    }

    stopProcessing()
    resolve(false)
    displayWooCommerceError('<div class="woocommerce-error">Invalid response</div>')
  }

  if (
    $body.hasClass('woocommerce-order-pay') ||
    $body.hasClass('woocommerce-add-payment-method')
  ) {
    handleUpdate()
  }

  /**
   * Submit card on Manual Order Payment
   */
  function submitOrderPay() {
    if (!$body.hasClass('woocommerce-order-pay')) {
      return true
    }

    startProcessing()

    if (payWithPaymentToken()) {
      return handlePaymentResult()
    }

    getBillingInfo().then(function (billing_info) {
      return showCardPopupWidget(billing_info)
    })

    return false
  }

  /**
   * Submit card on Payment method save
   */
  function submitPaymentMethodSave() {
    if (!isRevolutCardPaymentOptionSelected()) {
      return true
    }

    if (payWithPaymentToken()) {
      return handlePaymentResult()
    }

    getCustomerBaseInfo().then(function (billing_info) {
      if (isPaymentMethodSaveView()) {
        if (getPaymentMethod().widgetType == CARD_WIDGET_TYPES.Popup) {
          return showCardPopupWidget(billing_info)
        }

        instance.submit(billing_info)
      }
    })
  }

  function isPaymentMethodSaveView() {
    return (
      $('#wc-revolut-change-payment-method').length > 0 ||
      $payment_save.length ||
      $body.hasClass('woocommerce-add-payment-method')
    )
  }

  /**
   * Get checkout form entries as json
   * @returns {{}}
   */
  function getCheckoutFormData() {
    let current_form = $form

    if ($payment_save.length) {
      current_form = $payment_save
    }

    let checkout_form_data = {}

    checkout_form_data = current_form.serializeArray().reduce(function (acc, item) {
      acc[item.name] = item.value
      return acc
    }, {})

    if (checkout_form_data['shipping_country'] == '') {
      if (
        $('#ship-to-different-address-checkbox').length > 0 &&
        !$('#ship-to-different-address-checkbox').is(':checked')
      ) {
        checkout_form_data['shipping_country'] = checkout_form_data['billing_country']
      }
    }

    return checkout_form_data
  }

  /**
   * Submit credit card payment
   * @return {Promise(boolean)}
   */
  function validateWooCommerceCheckout() {
    return new Promise(function (resolve, reject) {
      startProcessing()

      if (wc_revolut.order_id && $order_review.length > 0) {
        resolve(true)
      }

      submitWooCommerceOrder().then(function (valid) {
        if (valid) {
          stopProcessing()
        }
        resolve(valid)
      })
    })
  }

  /**
   * Get billing info for manual order payments
   * @returns {Promise({})}
   */
  function getBillingInfo() {
    return new Promise(function (resolve, reject) {
      $.ajax({
        type: 'POST',
        url: getAjaxURL('get_order_pay_billing_info'),
        data: {
          security: wc_revolut.nonce.billing_info,
          order_id: wc_revolut.order_id,
          order_key: wc_revolut.order_key,
        },
        success: function (response) {
          if (shouldSavePaymentMethod()) {
            let target = document.getElementById('woocommerce-revolut-card-element')
            response.savePaymentMethodFor = target.dataset.savePaymentFor
          }
          resolve(response)
        },
        catch: function (err) {
          reject(err)
        },
      })
    })
  }

  /**
   * Get customer billing info for payment method save
   * @returns {Promise({})}
   */
  function getCustomerBaseInfo() {
    return new Promise(function (resolve, reject) {
      $.ajax({
        type: 'POST',
        url: getAjaxURL('get_customer_info'),
        data: {
          security: wc_revolut.nonce.customer_info,
        },

        success: function (response) {
          if (shouldSavePaymentMethod()) {
            let target = document.getElementById('woocommerce-revolut-card-element')
            response.savePaymentMethodFor = target.dataset.savePaymentFor
          }
          resolve(response)
        },
        catch: function (err) {
          reject(err)
        },
      })
    })
  }

  /**
   * Show/hide order button based on selected payment method
   */
  function togglePlaceOrderButton() {
    const currentPaymentMethod = getPaymentMethod()
    currentPaymentMethod.methodId !== PAYMENT_METHOD.RevolutPay
      ? $('#place_order').removeClass('hidden_by_revolut').show()
      : $('#place_order').addClass('hidden_by_revolut').hide()
  }

  /**
   * Get selected payment method
   * @returns {{}}
   */
  function getPaymentMethod() {
    const currentPaymentMethod = $('input[name="payment_method"]:checked').val()
    let target =
      currentPaymentMethod !== PAYMENT_METHOD.RevolutPay
        ? document.getElementById('woocommerce-revolut-card-element')
        : document.getElementById('woocommerce-revolut-pay-element')
    if (target == null) {
      return false
    }
    let publicId = target.dataset.publicId
    let merchantPublicKey = target.dataset.merchantPublicKey
    let total = target.dataset.total
    let mode = target.dataset.mode
    let currency = target.dataset.currency
    let locale = target.dataset.locale
    let textcolor = target.dataset.textcolor
    let shippingTotal = target.dataset.shippingTotal
    let widgetType = target.dataset.widgetType
    let hidePaymentMethod = target.dataset.hidePaymentMethod

    let savePaymentDetails = 0
    let savePaymentMethodFor = ''
    if (currentPaymentMethod === PAYMENT_METHOD.CreditCard) {
      if ($('#wc-revolut_cc-new-payment-method').length) {
        savePaymentDetails = $('#wc-revolut_cc-new-payment-method:checked').length
        savePaymentMethodFor = $('#wc-revolut_cc-new-payment-method').val()
      } else {
        savePaymentDetails = target.dataset.paymentMethodSaveIsMandatory
        savePaymentMethodFor = target.dataset.savePaymentFor
      }
    }

    return {
      methodId: currentPaymentMethod,
      target: target,
      publicId: publicId,
      total: total,
      mode: mode,
      currency: currency,
      locale: locale,
      textcolor: textcolor,
      savePaymentDetails: savePaymentDetails,
      savePaymentMethodFor: savePaymentMethodFor,
      merchantPublicKey: merchantPublicKey,
      shippingTotal: shippingTotal,
      widgetType: widgetType,
      hidePaymentMethod: hidePaymentMethod,
    }
  }

  $body.on('updated_checkout payment_method_selected', handleUpdate)
  $form.on('checkout_place_order_revolut_cc', handleCreditCardSubmit)

  $order_review.on('submit', function (e) {
    if (isRevolutPaymentMethodSelected() && $('.revolut_public_id').length === 0) {
      e.preventDefault()
      let isChangePaymentMethodPage = $('#wc-revolut-change-payment-method').length > 0

      if (isChangePaymentMethodPage) {
        submitPaymentMethodSave()
      } else {
        submitOrderPay()
      }
    }
  })

  $payment_save.on('submit', function (e) {
    if (isRevolutPaymentMethodSelected()) {
      if ($('.revolut_public_id').length === 0) {
        e.preventDefault()
        submitPaymentMethodSave()
      }
    }
  })

  if (wc_revolut.page === 'order_pay') {
    $(document.body).trigger('wc-credit-card-form-init')
  }
})
