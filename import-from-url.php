<?php
/*
Plugin Name: Import from URL
Plugin URI: http://micalexander.com
Author: michael alexander
Version: 0.0.0
Description: This plugin allows you to import a remote image from a youtube, vimeo or standard url and save to your local wordpress.
Leveraged: Lim Kai Yang's Grab & Save https://wordpress.org/plugins/save-grab/
*/

/**
 * Register with hook 'admin_enqueue_scripts', which can be used for admin CSS and JavaScript
 */
add_action( 'admin_enqueue_scripts', 'import_from_url_styles_and_script' );

/**
 * Enqueue plugin style-file
 */

function import_from_url_styles_and_script() {
  // Respects SSL, css file is relative to the current file
  wp_register_style( 'import_from_url_style', plugins_url( 'import_from_url_style.css', __FILE__ ) );
  wp_enqueue_style( 'import_from_url_style' );
  // Respects SSL, js file is relative to the current file
  wp_register_script( 'import_from_url_script', plugins_url( 'import_from_url_script.js', __FILE__ ) );
  wp_enqueue_script( 'import_from_url_script' );
}

class ImportFromUrl {

  var $imageName;

  function ImportFromUrl() {$this->__construct();}

  function __construct() {
    global $wp_version;

    if ( $wp_version < 3.5 ) {

      if ( basename( $_SERVER['PHP_SELF'] ) != "media-upload.php" ) return;

    } else {

      if ( basename( $_SERVER['PHP_SELF'] ) != "media-upload.php" && basename( $_SERVER['PHP_SELF'] ) != "post.php" && basename( $_SERVER['PHP_SELF'] ) != "post-new.php" ) return;
    }

    add_filter( "media_upload_tabs", array( &$this, "build_tab" ) );
    add_action( "media_upload_importFromUrl", array( &$this, "menu_handle" ) );
  }

  /*
   * Merge an array into middle of another array
   *
   * @param array $array the array to insert
   * @param array $insert array to be inserted
   * @param int $position index of array
   */
  function array_insert( &$array, $insert, $position ) {

    settype( $array, "array" );
    settype( $insert, "array" );
    settype( $position, "int" );

    //if pos is start, just merge them
    if ( $position==0 ) {

      $array = array_merge( $insert, $array );

    } else {

      //if pos is end just merge them
      if ( $position >= ( count( $array )-1 ) ) {

        $array = array_merge( $array, $insert );

      } else {

        //split into head and tail, then merge head+inserted bit+tail
        $head  = array_slice( $array, 0, $position );
        $tail  = array_slice( $array, $position );
        $array = array_merge( $head, $insert, $tail );

      }
    }

    return $array;

  }


  function build_tab( $tabs ) {

    $newtab = array(
      'importFromUrl' => __( 'Import from URL', 'importFromUrl' )
    );

    return $this->array_insert( $tabs, $newtab, 2 );

  }

  function menu_handle() {

    return wp_iframe( array( $this, "media_process" ) );

  }

  function fetch_image( $url ) {

    if ( function_exists( "curl_init" ) ) {

      return $this->curl_fetch_image( $url );

    } elseif ( ini_get( "allow_url_fopen" ) ) {

      return $this->fopen_fetch_image( $url );

    }
  }

  function curl_fetch_image( $url ) {

    $ch = curl_init();

    curl_setopt( $ch, CURLOPT_URL, $url );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

    $image = curl_exec( $ch );

    curl_close( $ch );

    return $image;
  }

  function fopen_fetch_image( $url ) {

    $image = file_get_contents( $url, false, $context );

    return $image;

  }

  function slugify( $text ) {

    // replace non letter or digits by -
    $text = preg_replace( '~[^\\pL\d]+~u', '-', $text );

    // trim
    $text = trim( $text, '-' );

    // transliterate
    $text = iconv( 'utf-8', 'us-ascii//TRANSLIT', $text );

    // lowercase
    $text = strtolower( $text );

    // remove unwanted characters
    $text = preg_replace( '~[^-\w]+~', '', $text );

    if ( empty( $text ) ) {
      return 'n-a';
    }

    return $text;

  }

  function media_process() {

    if ( $_POST['imageurl'] ) {

      $imageurl    = $_POST['imageurl'];
      $ext         = pathinfo( basename( $imageurl ) , PATHINFO_EXTENSION );
      $newfilename = $_POST['newfilename'] ? $_POST['newfilename'] . "." . $ext : basename( $imageurl );

    }
    elseif ( $_POST['youtubeurl'] ) {

      $supplied_url = $_POST['youtubeurl'];
      $pattern      = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i';
      preg_match( $pattern, $_POST['youtubeurl'], $matches );
      $id           = isset( $matches[1] ) ? $matches[1] : false;
      $content      = file_get_contents( "http://youtube.com/get_video_info?video_id=".$id );

      parse_str( $content, $video_info );

      $video_title = $this->slugify( $video_info['title'] );
      $imageurl    = $video_info['iurl'];
      $ext         = pathinfo( basename( $imageurl ) , PATHINFO_EXTENSION );
      $newfilename = $_POST['newfilename'] ? $_POST['newfilename'] . "." . $ext : $video_title . '.' . $ext;

    }
    elseif ( $_POST['vimeourl'] ) {

      $supplied_url = $_POST['vimeourl'];
      $pattern      = '/(?:player.vimeo.com\/video\/|vimeo.com\/)([0-9]+)\??/i';

      preg_match( $pattern, $_POST['vimeourl'], $matches );

      $id          = isset( $matches[1] ) ? $matches[1] : false;
      $content     = file_get_contents( 'http://vimeo.com/api/v2/video/' . $id . '.php' );
      $video_info  = unserialize( $content );
      $video_title = $this->slugify( $video_info[0]['title'] );
      $imageurl    = $video_info[0]['thumbnail_large'];
      $ext         = pathinfo( basename( $imageurl ) , PATHINFO_EXTENSION );
      $newfilename = $_POST['newfilename'] ? $_POST['newfilename'] . "." . $ext : $video_title . '.' . $ext;
    }

    if ( $imageurl ) {

      $imageurl         = stripslashes( $imageurl );
      $uploads          = wp_upload_dir();
      $post_id          = isset( $_GET['post_id'] )? (int) $_GET['post_id'] : 0;
      $filename         = wp_unique_filename( $uploads['path'], $newfilename, $unique_filename_callback = null );
      $wp_filetype      = wp_check_filetype( $filename, null );
      $fullpathfilename = $uploads['path'] . "/" . $filename;

      try {

        if ( !substr_count( $wp_filetype['type'], "image" ) ) {

          throw new Exception( basename( $imageurl ) . ' is not a valid image. ' . $wp_filetype['type']  . '' );
        }

        $image_string = $this->fetch_image( $imageurl );
        $fileSaved    = file_put_contents( $uploads['path'] . "/" . $filename, $image_string );

        if ( !$fileSaved ) {

          throw new Exception( "The file cannot be saved. Try checking you FTP settings" );

        }

        $attachment = array(
          'post_mime_type' => $wp_filetype['type'],
          'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
          'post_content'   => '',
          'post_status'    => 'inherit',
          'guid'           => $uploads['url'] . "/" . $filename
        );

        $attach_id = wp_insert_attachment( $attachment, $fullpathfilename, $post_id );

        if ( !$attach_id ) {

          throw new Exception( "Failed to save record into database." );

        }

        require_once ABSPATH . "wp-admin" . '/includes/image.php';

        $attach_data = wp_generate_attachment_metadata( $attach_id, $fullpathfilename );

        wp_update_attachment_metadata( $attach_id,  $attach_data );

      } catch ( Exception $e ) {

        $error = '<div id="message" class="error"><p>' . $e->getMessage() . '</p></div>';
      }

    }

    media_upload_header();

    if ( !function_exists( "curl_init" ) && !ini_get( "allow_url_fopen" ) ) {

      echo '<div id="message" class="error"><p><b>cURL</b> or <b>allow_url_fopen</b> needs to be enabled. Please consult your server Administrator.</p></div>';

    } elseif ( $error ) {

      echo $error;

    } else {

      if ( $fileSaved && $attach_id ) {

        echo '<div id="message" class="updated"><p>File saved.</p></div>';

      }
    }
?>
    <form action="" method="post" id="image-form" class="media-upload-form type-form" style="margin: 0; padding: 20px;">
    <h3 class="import-from-url">Choose the type of link/URL to import image from</h3>
    <div class="describe">
      <div style="padding: 5px 0;">
        <strong><em>Select image type.</em></strong>
      </div>
      <div class="select-src">
        <select id="url-choice">
          <option value="select">-- Select --</option>
          <option value="youtubeurl">Youtube</option>
          <option value="vimeourl">Vimeo</option>
          <option value="imageurl">Image</option>
        </select>
      </div>
      <div id="src-youtubeurl" class="image-src">
        <div>
          <strong><em>Enter the link/URL of a Youtube video.</em></strong>
        </div>
        <input type="text" name="youtubeurl">
        <div>
          <strong><em>Save as (optional)</em></strong>
        </div>
        <input type="text" name="newfilename">
        <div>
          <input type="submit" class="button" value="Import">
        </div>
      </div>
      <div id="src-vimeourl" class="image-src">
        <div>
          <strong><em>Enter the link/URL of a Vimeo video.</em></strong>
        </div>
        <input type="text" name="vimeourl">
        <div>
          <strong><em>Save as (optional)</em></strong>
        </div>
        <input type="text" name="newfilename">
        <div>
          <input type="submit" class="button" value="Import">
        </div>
      </div>
      <div id="src-imageurl" class="image-src">
        <div>
          <strong><em>Enter the link/URL of an image.</em></strong>
        </div>
        <input type="text" name="imageurl">
        <div>
          <strong><em>Save as (optional)</em></strong>
        </div>
        <input type="text" name="newfilename">
        <div>
          <input type="submit" class="button" value="Import">
        </div>
      </div>
    </div>

    </form>
    <?php

    if ( $attach_id ) {

      $this->media_upload_type_form( "image", $errors, $attach_id );

    }
?>
    <?php
  }


  /*
   * modification from media.php function
   *
   * @param unknown_type $type
   * @param unknown_type $errors
   * @param unknown_type $id
   */
  function media_upload_type_form( $type = 'file', $errors = null, $id = null ) {

    $post_id         = isset( $_REQUEST['post_id'] )? intval( $_REQUEST['post_id'] ) : 0;
    $form_action_url = admin_url( "media-upload.php?type=$type&tab=type&post_id=$post_id" );
    $form_action_url = apply_filters( 'media_upload_form_url', $form_action_url, $type );
?>

    <form enctype="multipart/form-data" method="post" action="<?php echo esc_attr( $form_action_url ); ?>" class="media-upload-form type-form validate" id="<?php echo $type; ?>-form">
    <input type="submit" class="hidden" name="save" value="" />
    <input type="hidden" name="post_id" id="post_id" value="<?php echo (int) $post_id; ?>" />

    <script type="text/javascript">
    //<![CDATA[
    jQuery(function($){

      var preloaded = $(".media-item.preloaded");

      if ( preloaded.length > 0 ) {

        preloaded.each(function(){

          prepareMediaItem({id:this.id.replace(/[^0-9]/g, '')},'');

        });

      }

      updateMediaForm();

    });
    //]]>
    </script>
    <?php wp_nonce_field( 'media-form' ); ?>

    <div id="media-items">
    <?php
    if ( $id ) {

      if ( !is_wp_error( $id ) ) {

        add_filter( 'attachment_fields_to_edit', 'media_post_single_attachment_fields_to_edit', 10, 2 );

        echo get_media_items( $id, $errors );

      } else {

        echo '<div id="media-upload-error">'.esc_html( $id->get_error_message() ).'</div>';

        exit;
      }
    }
?>
    </div>
    <p class="savebutton ml-submit">
    <input type="submit" class="button" name="save" value="<?php esc_attr_e( 'Save all changes' ); ?>" />
    </p>
    </form>

    <?php
  }
}

new ImportFromUrl();
?>
