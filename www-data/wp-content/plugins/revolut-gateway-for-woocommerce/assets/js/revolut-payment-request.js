jQuery(function ($) {
  let wcAddToCartButton = $('.single_add_to_cart_button')
  let orderSelectedShippingOption = null
  let paymentRequestType = null
  let paymentRequest = null
  let address_info = null
  let wc_order_id = null

  function handleError(message) {
    $.blockUI({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } })

    if (paymentRequest) {
      paymentRequest.destroy()
    }

    $.ajax({
      type: 'POST',
      data: {
        revolut_payment_request_error: message,
      },
      url: getAjaxURL('set_error_message'),
      success: function () {
        window.location.reload()
      },
      error: function () {
        window.location.reload()
      },
    })
  }

  function logError(message) {
    $.ajax({
      type: 'POST',
      data: {
        revolut_payment_request_error: message,
      },
      url: getAjaxURL('log_error'),
    })
  }

  function getAjaxURL(endpoint, controller = 'revolut_payment_request_') {
    return wc_revolut_payment_request_params.ajax_url
      .toString()
      .replace('%%wc_revolut_gateway_ajax_endpoint%%', `${controller}${endpoint}`)
  }

  function getProductAttributes() {
    const select = $('.variations_form').find('.variations select'),
      data = {}

    select.each(function () {
      const attribute_name = $(this).data('attribute_name') || $(this).attr('name')
      data[attribute_name] = $(this).val() || ''
    })

    return data
  }

  function checkCartCreateErrors() {
    if (!wcAddToCartButton.is('.disabled')) {
      return false
    }

    if (wcAddToCartButton.is('.wc-variation-is-unavailable')) {
      return wc_add_to_cart_variation_params.i18n_unavailable_text
    }

    if (wcAddToCartButton.is('.wc-variation-selection-needed')) {
      return wc_add_to_cart_variation_params.i18n_make_a_selection_text
    }

    return wc_revolut_payment_request_params.error_messages.cart_create_failed
  }

  function createCart(add_to_cart = 0, revpay = false) {
    return new Promise((resolve, reject) => {
      if (checkCartCreateErrors()) {
        let errorMsg = checkCartCreateErrors()

        if (revpay) {
          return reject(errorMsg)
        }

        handleError(errorMsg)
        return resolve(false)
      }

      if (wc_revolut_payment_request_params.is_cart_page) {
        return resolve({ success: true })
      }

      let product_data = {}
      product_data['add_to_cart'] = add_to_cart
      product_data['is_revolut_pay'] = revpay ? 1 : 0
      product_data['security'] = wc_revolut_payment_request_params.nonce.add_to_cart
      product_data['revolut_public_id'] =
        wc_revolut_payment_request_params.revolut_public_id
      product_data['product_id'] = $('.single_add_to_cart_button').val()
      product_data['qty'] = $('.quantity .qty').val()
      product_data['attributes'] = []

      if ($('.single_variation_wrap').length) {
        product_data['product_id'] = $('.single_variation_wrap')
          .find('input[name="product_id"]')
          .val()
      }

      if ($('.variations_form').length) {
        product_data['attributes'] = getProductAttributes()
      }

      $.ajax({
        type: 'POST',
        data: product_data,
        url: getAjaxURL('add_to_cart'),
        success: function (response) {
          if (!response.success) {
            if (revpay) {
              displayErrorMessage(
                wc_revolut_payment_request_params.error_messages.cart_create_failed,
              )
              return resolve(false)
            }

            handleError(
              wc_revolut_payment_request_params.error_messages.cart_create_failed,
            )
            return resolve(false)
          }
          wc_revolut_payment_request_params.total = response.total.amount
          wc_revolut_payment_request_params.nonce.checkout = response.checkout_nonce
          resolve(response)
        },
      })
    })
  }

  function getShippingOptions(address) {
    let address_data = {
      security: wc_revolut_payment_request_params.nonce.shipping,
      revolut_public_id: wc_revolut_payment_request_params.revolut_public_id,
      country: address.country,
      state: address.region,
      postcode: address.postalCode,
      city: address.city,
      address: '',
      address_2: '',
      is_product_page: wc_revolut_payment_request_params.is_product_page,
      require_shipping: wc_revolut_payment_request_params.shipping_required,
    }

    return new Promise((resolve, reject) => {
      $.ajax({
        type: 'POST',
        data: address_data,
        url: getAjaxURL('get_shipping_options'),
        success: function (response) {
          if (response['shippingOptions'] && response['shippingOptions'].length !== 0) {
            orderSelectedShippingOption = response['shippingOptions'][0]['id']
          }

          resolve(response)
        },
        error: function (error) {
          reject(error)
        },
      })
    })
  }

  function updateShippingOptions(shippingOption) {
    let shipping_option_data = {
      revolut_public_id: wc_revolut_payment_request_params.revolut_public_id,
      security: wc_revolut_payment_request_params.nonce.update_shipping,
      shipping_method: [shippingOption.id],
      is_product_page: wc_revolut_payment_request_params.is_product_page,
    }

    return new Promise((resolve, reject) => {
      $.ajax({
        type: 'POST',
        data: shipping_option_data,
        url: getAjaxURL('update_shipping_method'),
        success: function (response) {
          resolve(response)
        },
        error: function (error) {
          reject(error)
        },
      })
    })
  }

  function updatePaymentTotal() {
    let shipping_option_data = {
      revolut_public_id: wc_revolut_payment_request_params.revolut_public_id,
      security: wc_revolut_payment_request_params.nonce.update_order_total,
    }

    return new Promise((resolve, reject) => {
      $.ajax({
        type: 'POST',
        data: shipping_option_data,
        url: getAjaxURL('update_payment_total'),
        success: function (response) {
          resolve(response)
        },
        error: function (error) {
          reject(error)
        },
      })
    })
  }

  function udpateExpressCheckoutParams(revpay = false) {
    return new Promise((resolve, reject) => {
      $.ajax({
        type: 'POST',
        data: {
          security: revpay
            ? wc_revolut_payment_request_params.nonce.get_express_checkout_params
            : wc_revolut_payment_request_params.nonce.get_payment_request_params,
        },
        url: getAjaxURL(
          revpay ? 'get_express_checkout_params' : 'get_payment_request_params',
        ),
        success: function (response) {
          if (response.success) {
            wc_revolut_payment_request_params.revolut_public_id =
              response.revolut_public_id
            wc_revolut_payment_request_params.nonce.checkout = response.checkout_nonce
            return resolve()
          }
          reject('')
        },
        error: function (error) {
          reject(error)
        },
      })
    })
  }

  function loadOrderData() {
    return new Promise((resolve, reject) => {
      $.ajax({
        type: 'POST',
        data: {
          revolut_public_id: wc_revolut_payment_request_params.revolut_public_id,
          security: wc_revolut_payment_request_params.nonce.load_order_data,
        },
        url: getAjaxURL('load_order_data'),
        success: function (response) {
          resolve(response)
        },
        error: function (error) {
          reject(error)
        },
      })
    })
  }

  function cancelOrder(messages) {
    $.blockUI({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } })

    return new Promise((resolve, reject) => {
      $.ajax({
        type: 'POST',
        data: {
          revolut_public_id: wc_revolut_payment_request_params.revolut_public_id,
          security: wc_revolut_payment_request_params.nonce.cancel_order,
        },
        url: getAjaxURL('cancel_order'),
        success: function (response) {
          handleError(messages)
        },
      })
    })
  }

  function submitOrder(errorMessage = '', revolut_gateway = 'revolut_payment_request') {
    $.blockUI({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } })

    let data = {}
    data['is_express_checkout'] = 1
    data['revolut_gateway'] = revolut_gateway
    data['revolut_public_id'] = wc_revolut_payment_request_params.revolut_public_id
    data['revolut_payment_error'] = errorMessage
    data['wc_order_id'] = wc_order_id
    data['reload_checkout'] = 0
    data['revolut_save_payment_method'] = 0
    data['wc-revolut_cc-payment-token'] = ''

    $.ajax({
      type: 'POST',
      data: data,
      url: getAjaxURL('process_payment_result', 'wc_revolut_'),
      success: function (response) {
        if (response.result === 'success') {
          window.location.href = response.redirect
          return
        }
        $.unblockUI()
        $('.blockUI.blockOverlay').hide()

        if (!response.messages || typeof response.messages == 'undefined') {
          response.messages = `<div class="woocommerce-error">${wc_revolut_payment_request_params.error_messages.checkout_general}</div>`
        }

        cancelOrder(response.messages)
      },
    })
  }

  function submitWooCommerceOrder(payment_method = 'revolut_payment_request') {
    return new Promise((resolve, reject) => {
      $.ajax({
        type: 'POST',
        data: {
          payment_method: payment_method,
          _wpnonce: wc_revolut_payment_request_params.nonce.checkout,
          shipping_method: [orderSelectedShippingOption],
          payment_request_type: paymentRequestType,
          revolut_public_id: wc_revolut_payment_request_params.revolut_public_id,
          shipping_required: wc_revolut_payment_request_params.shipping_required ? 1 : 0,
          address_info: address_info,
          revolut_create_wc_order: 1,
          is_express_checkout: 1,
        },
        url: getAjaxURL('create_order'),
        success: function (response) {
          if (true === response.reload) {
            window.location.reload()
            return
          }

          if (response.result === 'revolut_wc_order_created') {
            wc_order_id = response['wc_order_id']
            return resolve(true)
          }

          if (typeof response.messages == 'undefined') {
            response.messages = `<div class="woocommerce-error">${wc_revolut_payment_request_params.error_messages.checkout_general}</div>`
          }
          logError('submitWooCommerceOrder failed: ' + JSON.stringify(response))
          return cancelOrder($(response.messages).text())
        },
        error: function (jqXHR, textStatus, errorThrown) {
          cancelOrder(errorThrown)
        },
      })
    })
  }

  function displayErrorMessage(message) {
    if (!message.includes('woocommerce-error')) {
      message = `<div class="woocommerce-error">${message}</div>`
    }

    $('.woocommerce-error').remove()

    if (wc_revolut_payment_request_params.is_product_page) {
      var element = $('.product').first()
      element.before(message)

      $('html, body').animate(
        {
          scrollTop: element.prev('.woocommerce-error').offset().top,
        },
        600,
      )
    } else {
      var $form = $('.shop_table.cart').closest('form')
      $form.before(message)
      $('html, body').animate(
        {
          scrollTop: $form.prev('.woocommerce-error').offset().top,
        },
        600,
      )
    }
  }

  function initPaymentRequestButton() {
    if ($('#revolut-payment-request-button').length < 1) {
      return false
    }

    // remove duplicated instances
    if ($('.wc-revolut-payment-request-instance').length > 1) {
      $('.wc-revolut-payment-request-instance').not(':last').remove()
    }

    udpateExpressCheckoutParams().then(function () {
      const RC = RevolutCheckout(wc_revolut_payment_request_params.revolut_public_id)
      const revolutPaymentReuqestButton = document.getElementById(
        'revolut-payment-request-button',
      )

      paymentRequest = RC.paymentRequest({
        target: revolutPaymentReuqestButton,
        requestShipping: wc_revolut_payment_request_params.shipping_required,
        shippingOptions: wc_revolut_payment_request_params.free_shipping_option,
        onClick() {
          if (!wc_revolut_payment_request_params.shipping_required) {
            return createCart(1)
          }
        },
        onShippingOptionChange: selectedShippingOption => {
          orderSelectedShippingOption = selectedShippingOption['id']
          return updateShippingOptions(selectedShippingOption)
        },
        onShippingAddressChange: selectedShippingAddress => {
          return createCart(1).then(function () {
            return getShippingOptions(selectedShippingAddress)
          })
        },
        onSuccess() {
          submitOrder()
        },
        validate(address) {
          address_info = address
          return submitWooCommerceOrder()
        },
        onError(error) {
          let errorMessage = error

          if (error['message']) {
            errorMessage = error['message']
          }

          if (errorMessage == 'Unknown') {
            errorMessage =
              wc_revolut_payment_request_params.error_messages.checkout_general
          }

          if (wc_order_id) {
            return submitOrder(errorMessage)
          }

          displayErrorMessage(errorMessage)
        },
        buttonStyle: {
          action: wc_revolut_payment_request_params.payment_request_button_type,
          size: wc_revolut_payment_request_params.payment_request_button_size,
          variant: wc_revolut_payment_request_params.payment_request_button_theme,
          radius: wc_revolut_payment_request_params.payment_request_button_radius,
        },
      })

      paymentRequest.canMakePayment().then(result => {
        if (result) {
          paymentRequest.render()
        } else {
          paymentRequest.destroy()
        }
      })
    })
  }

  function initRevolutPayExpressCheckoutButton() {
    if ($('#revolut-pay-express-checkout-button').length < 1) {
      return false
    }

    // remove duplicated instances
    if ($('.wc-revolut-pay-express-checkout-instance').length > 1) {
      $('.wc-revolut-pay-express-checkout-instance').not(':last').remove()
    }

    udpateExpressCheckoutParams(true).then(function () {
      instance = RevolutCheckout.payments({
        locale: wc_revolut_payment_request_params.locale,
        publicToken: wc_revolut_payment_request_params.publicToken,
      })

      instance.revolutPay.mount('#revolut-pay-express-checkout-button', {
        currency: wc_revolut_payment_request_params.currency,
        totalAmount: parseInt(wc_revolut_payment_request_params.total),
        requestShipping: true,
        validate() {
          return createCart(1, true).then(function (result) {
            if (result && result.success) {
              if (wc_revolut_payment_request_params.is_cart_page) {
                return updatePaymentTotal()
              }

              return Promise.resolve(true)
            }
          })
        },
        createOrder: () => {
          return { publicId: wc_revolut_payment_request_params.revolut_public_id }
        },
        buttonStyle: {
          cashbackCurrency: wc_revolut_payment_request_params.currency,
          variant: wc_revolut_payment_request_params.revolut_pay_button_theme,
          size: wc_revolut_payment_request_params.revolut_pay_button_size,
          radius: wc_revolut_payment_request_params.revolut_pay_button_radius,
        },
      })

      instance.revolutPay.on('payment', function (event) {
        switch (event.type) {
          case 'success':
            $.blockUI({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } })

            loadOrderData().then(function (order_data) {
              address_info = order_data.address_info
              orderSelectedShippingOption = order_data.selected_shipping_option
              submitWooCommerceOrder('revolut_pay')
                .then(function () {
                  submitOrder('', 'revolut_pay')
                })
                .catch(error => {
                  displayErrorMessage(error)
                  $.unblockUI()
                  $('.blockUI.blockOverlay').hide()
                })
            })
            break
          case 'error':
            displayErrorMessage(event.error.message)
            break
        }
      })
    })
  }

  $('.quantity').on('input', '.qty', function () {
    if (
      !paymentRequest ||
      wcAddToCartButton.is('.disabled') ||
      wc_revolut_payment_request_params.is_cart_page
    ) {
      return
    }
    paymentRequest.updateWith(createCart())
  })

  $(document.body).on('woocommerce_variation_has_changed', function () {
    if (
      !paymentRequest ||
      wcAddToCartButton.is('.disabled') ||
      wc_revolut_payment_request_params.is_cart_page
    ) {
      return
    }
    paymentRequest.updateWith(createCart())
  })

  $(document.body).on('updated_cart_totals', function () {
    initPaymentRequestButton()
    initRevolutPayExpressCheckoutButton()
  })

  initPaymentRequestButton()
  initRevolutPayExpressCheckoutButton()
})
