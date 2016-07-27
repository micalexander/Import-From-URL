jQuery(function($) {

  $('#url-choice').on('change', function() {

    $('.image-src')
      .hide();

    $('#src-' + $(this).val())
      .fadeIn();

  });

  if ($('#media-items').length > 0) {

    $('html, body').css({
      'height': 'auto'
    });

  } else {

    $('html, body').css({
      'height': '100%'
    });

  };
});
