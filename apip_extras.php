<?php
    /**
     * @package apip_extras
     * @author Chris Smearing, et al
     * @version 0.1.1
     */
    /*
     Plugin Name: APIP Extras
     Plugin URI: #
     Description: Additional functions for the Amazon Product in a Post Plugin
        - Reference plugin: https://wordpress.org/plugins/amazon-product-in-a-post-plugin/
        - Cache responses so the minimum number of requests are made per page
            - for example, the body content has multiple instances of the shortcode to create CTAs at the top of the post, as well as at the bottom
     Author: Chris Smearing
     Version: 0.1.1
     Author URI: https://chris-smearing.github.io
     */

    class AmazonCache {
        public $cache = [];

        public function add($asin, $key, $value) {
            $this->cache[$asin][$key] = $value;
        }

        public function get($asin, $key) {
            if ( array_key_exists($asin, $this->cache) ) {
                return $this->cache[$asin][$key];
            } else {
                return null;
            }
        }
    }
    $amazon_cache = null;

    /***
    * Create an instance of the cache from a template
    ***/
    function apip_init_amazon_cache() {
        global $amazon_cache;
        $amazon_cache = new AmazonCache();
    }

    // Remove plugin's default styles
    remove_action('wp_head', 'aws_prodinpost_addhead', 10);
    
    /***
    * Create special markup for the Top Choice for a single product review post
    ***/
    function apip_single_top_choices($asin, $aff_link) {
        global $amazon_cache;

        // can't continue unless an instance of the cache is available
        if ( empty($amazon_cache) ) {
            return null;
        }

        // see if the price is available in the cache
        $widget_price_button = $amazon_cache->get($asin, 'price_button');

        // if not, then do a fetch
        if( !empty($asin) && empty($widget_price_button) ) {
            apip_do_amazon_request($asin, $aff_link, 'ybdigc-20');
            $widget_price_button = $amazon_cache->get($asin, 'price_button');
        }

        // get just the text of the button
        $price_button = [];
        preg_match('/<button>(.*)<\/button>/i', $widget_price_button, $price_button);

        if ( ! empty($price_button) ) {
            $widget_price_button = $price_button[1];
            return apip_fix_button_text($widget_price_button);
        } else {
            return $widget_price_button;
        }        
    }
    
    // Replace text of the button with a minimal string
    function apip_fix_button_text($button_text) {
        $button_text = preg_replace('/<button>.*<\/button>/i', '<button>See Price at Amazon</button>', $button_text);
        return $button_text;
    }
    
    // Use the plugin's shortcode to perform a request, and store the results to the cache
    function apip_do_amazon_request( $asin, $aff_link, $partner_id ) {
        global $amazon_cache;
        $partnerid = null;

        // can't continue unless an instance of the cache is available
        if ( empty($amazon_cache) ) {
            return;
        }

        $partnerid_test = wp_kses_post( $partner_id );
        if( !empty($partnerid_test) ) {
            $partnerid = ' partner_id="' . $partnerid_test . '"';
        }
        $asin_request = ''.do_shortcode('[amazon-element asin="'.$asin.'" fields="med-image,lg-image,link_clean,price_clean" container="" msg_instock="from Amazon" ' . $partnerid . ' msg_outofstock="See at Amazon"]').'';

        if ( empty($asin_request) ) {
            $new_price_button = '<a target="_blank" href="'. $aff_link  .'" ><button>See Price at Amazon</button></a>';
        } else {
            $price_button = [];
            preg_match('/<a.*(?=button).*/i', $asin_request, $price_button);

            $new_price_button2 = preg_replace('/<a(.*?)href=(["\'])(.*?)\\2(.*?)>/i', '<a target="_blank" href="' . wp_kses_post( $aff_link ) . '">', $price_button[0]);
            $new_price_button = apip_fix_button_text($new_price_button2);
        }
        // add the price button to the cache
        $amazon_cache->add($asin, 'price_button', $new_price_button);

        // transform the images
        preg_match('/.*amazon-element-med-image.*<img src="(.+?)"/', $asin_request, $md_url_match);
        preg_match("/.*amazon-element-lg-image.*<img src=\"(.+?)\"/", $asin_request, $lrg_url_match);
        
        // Avoid mixed-content warnings
        $md_src = str_replace('http://images.amazon.com', 'https://images-na.ssl-images-amazon.com',$md_url_match[1]);
        $lrg_src = str_replace('http://images.amazon.com', 'https://images-na.ssl-images-amazon.com',$lrg_url_match[1]);
        
        if ( $md_src ) {
            list($medwidth, $medheight) =  getimagesize($md_src);
        }
        if ( $lrg_src) {
            list($lgwidth, $lgheight) = getimagesize($lrg_src);
        }
        
        // create the div and image markup
        if ( $medheight >= 300 && $medwidth >= 300) {
            $imageout = '<div class="amazon-image-wrapper loop2">';
            $imageout .= '<a href="'.$aff_link.'" target="_blank">';
            $imageout .= '<img src="'.$md_src.'" srcset="'.$md_src.' 300w, '.$lrg_src.' 500w" sizes="(max-width: 550px) 500px, 170px">';
            $imageout .= '</a></div>';
        } else {
            $imageout = '<div class="amazon-image-wrapper loop3">';
            $imageout .= '<a href="'.$aff_link.'" target="_blank">';
            $imageout .= '<img src="'.$lrg_src.'">';
            $imageout .= '</a></div>';
        }

        // add the image to the cache
        $amazon_cache->add($asin, 'image', $imageout);
    }

    function apip_custom_display( $atts ) {
        global $amazon_cache;
        $output = '';
        $apip_display = shortcode_atts( 
                array(
                    'width' => '',
                    'title' => '',
                    'prod_name' => '',
                    'prod_desc' => '',
                    'prod_img' => '',
                    'amazonid' => '',
                    'partner_id' => '',
                    'aff_source' => 'Amazon',
                    'aff_link' => '',
                    'aff_prodid' => '',
                    'aff_link2' => '',
                    'aff_source2' => '',
                    ), $atts );
        $asin = wp_kses_post( $apip_display[ 'amazonid' ] );
        $prodimage = wp_kses_post( $apip_display[ 'prod_img' ] );
        $aff_link = wp_kses_post( $apip_display[ 'aff_link' ] );
        $imageout = null;

        if ( empty($amazon_cache) ) {
            return null;
        }
        
        if ( !empty($asin) ) {
            $new_price_button = $amazon_cache->get($asin, 'price_button');
            
            // if price doesn't exist in the cache, do the fetch
            if ( empty( $new_price_button )) {
                apip_do_amazon_request($asin, $aff_link, $apip_display[ 'partner_id' ]);
                $new_price_button = $amazon_cache->get($asin, 'price_button');
            }

            // regardless of the initial state, the image should now be in the cache
            $imageout = $amazon_cache->get($asin, 'image');
        }

        // if the image is specified and the existing image is from Amazon
        if( !empty($prodimage) && ! strstr($imageout, 'loop1') ) {
            $imageout = '<div class="amazon-image-wrapper loop1">';
            $imageout .= '<a href="'.$aff_link.'" target="_blank">';
            $imageout .= '<img src="'.$prodimage.'">';
            $imageout .= '</a>';
            $imageout .= '</div>';

            // store the new image back to the cache
            if ( !empty($asin) ) {
                $amazon_cache->add($asin, 'image', $imageout);
            }
        } else {
            // $prodimage is not assigned, use ASIN image already assigned
        }
        
        $insertwidth = wp_kses_post( $apip_display[ 'width' ] );
        if ( !empty($asin) ) {
            // conditional here to prevent next conditions when ASIN is specified
        } else if ( $insertwidth == 6 ) {
            $aff_link = wp_kses_post( $apip_display[ 'aff_link' ] );
            $aff_source = wp_kses_post( $apip_display[ 'aff_source' ] );
            $new_price_button = '<a href="'.$aff_link.'" target="_blank"><button>See Price at '.$aff_source.'</button></a>';
        } else {
            $aff_link = wp_kses_post( $apip_display[ 'aff_link' ] );
            $aff_source = wp_kses_post( $apip_display[ 'aff_source' ] );
            $new_price_button = '<a href="'.$aff_link.'" target="_blank"><button>See Price at '.$aff_source.'</button></a>';
        }
        
        // suppose this product links to Amazon and also Walmart, put the Walmart link in the final output
        $aff_link2 = wp_kses_post( $apip_display[ 'aff_link2' ] );
        if ( !empty($aff_link2) ) {
            $aff_link2 = wp_kses_post( $apip_display[ 'aff_link2' ] );
            $aff_source2 = wp_kses_post( $apip_display[ 'aff_source2' ] );
            
            if ( $insertwidth == 6 ) {
                $two_price_button = '<a href="'.$aff_link2.'" target="_blank"><button>See Price at '.$aff_source2.'</button></a>';
            } else {
                $two_price_button = '<a href="'.$aff_link2.'" target="_blank"><button>See Price at '.$aff_source2.'</button></a>';
            }
        } else {
            $two_price_button = '';
        }
        
        // create the final output markup and return it
        $output .= '<div class="amazonpip columns-'.wp_kses_post( $apip_display[ 'width' ] ).'">';
        $output .= '<div class="rank_title">'.wp_kses_post( $apip_display[ 'title' ] ).'</div>';
        $output .= $imageout;
        $output .= '<div class="apip-info">';
        $output .= '<h3>'.wp_kses_post( $apip_display[ 'prod_name' ] ).'</h3>';
        $output .= '<p>'.wp_kses_post( $apip_display[ 'prod_desc' ] ).'</p>';
        $output .= $new_price_button;
        $output .= $two_price_button;
        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }
    add_shortcode( 'apip_insert_product', 'apip_custom_display' );
    
    
    ?>
