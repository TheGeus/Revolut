jQuery(document).ready(function ($) {
  $('#woocommerce_revolut_cc_restore_button').hide()
  const revolutMode = $('#woocommerce_revolut_mode')
  const apiDevKey = $('#woocommerce_revolut_api_key_dev').parents().closest('tr')
  const apiSandboxKey = $('#woocommerce_revolut_api_key_sandbox').parents().closest('tr')
  const apiLiveKey = $('#woocommerce_revolut_api_key').parents().closest('tr')
  const payment_action = $('#woocommerce_revolut_payment_action')
  const capture_checkbox = $('#woocommerce_revolut_accept_capture')
    .parents()
    .closest('tr')
  $('#woocommerce_revolut_payment_request_onboard_applepay').hide()

  if (revolutMode.val() == 'sandbox') {
    change('sandbox')
  } else if (revolutMode.val() == 'dev') {
    change('dev')
  } else {
    change('live')
  }

  if (payment_action.val() == 'authorize') {
    capture_checkbox.show()
  } else {
    capture_checkbox.hide()
  }

  revolutMode.on('change', function () {
    var mode = $(this).val()

    if (mode == 'sandbox') {
      change('sandbox')
    } else if (mode == 'dev') {
      change('dev')
    } else {
      change('live')
    }
  })

  // change payment action
  payment_action.on('change', function () {
    if (payment_action.val() == 'authorize') {
      capture_checkbox.show()
    } else {
      capture_checkbox.hide()
    }
  })

  // change API mode
  function change(mode) {
    if (mode == 'sandbox') {
      apiSandboxKey.show()
      apiLiveKey.hide()
      apiDevKey.hide()
    } else if (mode == 'dev') {
      apiSandboxKey.hide()
      apiLiveKey.hide()
      apiDevKey.show()
      apiLiveKey.hide()
    } else {
      apiSandboxKey.hide()
      apiDevKey.hide()
      apiLiveKey.show()
    }
  }

  // onboard apple pay domain
  $('.setup-applepay-domain').click(function (event) {
    event.preventDefault()

    $.ajax({
      type: 'POST',
      url: ajaxurl,
      data: {
        action: 'wc_revolut_onboard_applepay_domain',
      },
      success: function (response) {
        let message = ''
        const setup_button = $('.setup-applepay-domain')
        if (!response.success && response.message) {
          message = response.message
        }

        if (response.success) {
          setup_button.text('Success')
          setup_button.prop('disabled', true)
          return true
        }

        $('.setup-applepay-domain-error')
          .show()
          .text('Setup Failed: ' + message)
      },
    })
  })

  $(document).on('change', '.revolut_styling_option_enable', function () {
    if (!$('.revolut_styling_option_enable').is(':checked')) {
      $('.revolut_styling_option').parents('tr').hide()
      restoreStylinOptions()
    } else {
      $('.revolut_styling_option').parents('tr').show()
    }
  })

  $(document).on('click', '.revolut_style_restore', function () {
    restoreStylinOptions()
  })

  if (!$('.revolut_styling_option_enable').is(':checked')) {
    $('.revolut_styling_option').parents('tr').hide()
  }

  function restoreStylinOptions() {
    $('#woocommerce_revolut_cc_widget_background_color')
      .val(default_options.default_bg_color)
      .trigger('change')
    $('#woocommerce_revolut_cc_widget_text_color')
      .val(default_options.default_text_color)
      .trigger('change')
    $('#woocommerce_revolut_cc_revolut_logo_color').prop('selectedIndex', 0).val()
  }

  adjustCardWidgetStylingOptions()

  $(document).on('change', '#woocommerce_revolut_cc_card_widget_type', function () {
    adjustCardWidgetStylingOptions()
  })

  function adjustCardWidgetStylingOptions() {
    if ($('#woocommerce_revolut_cc_card_widget_type').val() === 'popup') {
      $('#woocommerce_revolut_cc_styling_title').hide()
      $('#woocommerce_revolut_cc_widget_styling').parents('table').hide()
    } else {
      $('#woocommerce_revolut_cc_styling_title').show()
      $('#woocommerce_revolut_cc_widget_styling').parents('table').show()
    }
  }
})
