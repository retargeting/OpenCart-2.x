<?php
/**
 * Retargeting Module for OpenCart 2.x
 *
 * catalog/controller/module/retargeting.php
 */

include_once 'Retargeting_REST_API_Client.php';

class ControllerModuleRetargeting extends Controller {

	public function index() {

        /* ---------------------------------------------------------------------------------------------------------------------
         * Setup the protocol
         * ---------------------------------------------------------------------------------------------------------------------
         */
        if (isset($this->request->server['HTTPS']) && (($this->request->server['HTTPS'] == 'on') || ($this->request->server['HTTPS'] == '1'))) {
            $data['shop_url'] = $this->config->get('config_ssl');
        } else {
            $data['shop_url'] = $this->config->get('config_url');
        }

        /* ---------------------------------------------------------------------------------------------------------------------
         * Load the module's language file
         * ---------------------------------------------------------------------------------------------------------------------
         */
		$this->language->load('module/retargeting');

        /* ---------------------------------------------------------------------------------------------------------------------
         * Load models that we might need access to
         * ---------------------------------------------------------------------------------------------------------------------
         */
        $this->load->model('checkout/order');
        $this->load->model('setting/setting');
        $this->load->model('design/layout');
        $this->load->model('catalog/category');
        $this->load->model('catalog/manufacturer');
        $this->load->model('catalog/product');
        $this->load->model('catalog/information');
//        $this->load->model('marketing/coupon'); /* Available only in the admin/ area */
        // $this->load->model('checkout/coupon');

        /* ---------------------------------------------------------------------------------------------------------------------
         * Get the saved values from the admin area
         * ---------------------------------------------------------------------------------------------------------------------
         */
		$data['api_key_field'] = $this->config->get('retargeting_apikey');
		$data['api_secret_field'] = $this->config->get('retargeting_token');

        $data['retargeting_setEmail'] = htmlspecialchars_decode($this->config->get('retargeting_setEmail'));
		$data['retargeting_addToCart'] = htmlspecialchars_decode($this->config->get('retargeting_addToCart'));
		$data['retargeting_clickImage'] = htmlspecialchars_decode($this->config->get('retargeting_clickImage'));
		$data['retargeting_commentOnProduct'] = htmlspecialchars_decode($this->config->get('retargeting_commentOnProduct'));
		$data['retargeting_mouseOverPrice'] = htmlspecialchars_decode($this->config->get('retargeting_mouseOverPrice'));
		$data['retargeting_setVariation'] = htmlspecialchars_decode($this->config->get('retargeting_setVariation'));

        /**
         * --------------------------------------
         *             Products feed
         * --------------------------------------
         **/
        /* XML Request intercepted, kill everything else and output */
        if (isset($_GET['xml']) && $_GET['xml'] === 'retargeting') {

            /* Modify the header */
            header('Content-Type: application/xml');

            /* Pull ALL products from the database */
            $products = $this->model_catalog_product->getProducts();

            $output = '<products>';
            foreach ($products as $product) {
                $product['quantity'] = (isset($product['quantity']) && !empty($product['quantity'])) ? 1 : 0;
                $product_promotional_price = isset($product['special']) ? $product['special'] : 0;
                $product_url = htmlspecialchars($this->url->link('product/product', 'product_id=' . $product['product_id']), ENT_XML1);
                $product_image_url = $data['shop_url'] . 'image/' . $product['image'];
                $product_image_url = htmlspecialchars($product_image_url, ENT_XML1);
                $output .= "
                                <product>
                                    <id>{$product['product_id']}</id>
                                    <stock>{$product['quantity']}</stock>
                                    <price>{$product['price']}</price>
                                    <promo>{$product_promotional_price}</promo>
                                    <url>{$product_url}</url>
                                    <image>{$product_image_url}</image>
                                </product>
                            ";
            }
            $output .= '</products>';
            echo $output;
            die();
        }
        /* --- END PRODUCTS FEED  --- */



        /**
         * ---------------------------------------------------------------------------------------------------------------------
         *
         * API poach && Discount codes generator
         *
         * ---------------------------------------------------------------------------------------------------------------------
         *
         *
         * ********
         * REQUEST:
         * ********
         * POST : key​=your_retargeting_key
         * GET : type​=0​&value​=30​&count​=3
         * * type => (Integer) 0​: Fixed; 1​: Percentage; 2​: Free Delivery;
         * * value => (Float) actual value of discount
         * * count => (Integer) number of discounts codes to be generated
         *
         *
         * *********
         * RESPONDS:
         * *********
         * json with the discount codes
         * * ['code1', 'code2', ... 'codeN']
         *
         *
         * STEP 1: check $_POST
         * STEP 2: add the discount codes to the local database
         * STEP 3: expose the codes to Retargeting
         * STEP 4: kill the script
         */
        if (isset($_POST) && isset($_POST['key']) && ($_POST['key'] === $data['api_key_field'])) {

            /* -------------------------------------------------------------
             * STEP 1: check $_POST and validate the API Key
             * -------------------------------------------------------------
             */

            /*
            include_once 'Retargeting_REST_API_Client.php';
            $client = new Retargeting_REST_API_Client($data['api_key_field'], $data['api_secret_field']);
            $client->setResponseFormat("json");
            $client->setDecoding(false);
            $client->setApiVersion('1.0');
            $client->setApiUri('https://retargeting.ro/api');
            */

            /* Check and adjust the incoming values */
            $discount_type = (isset($_GET['type'])) ? (filter_var($_GET['type'], FILTER_SANITIZE_NUMBER_INT)) : 'Received other than int';
            $discount_value = (isset($_GET['value'])) ? (filter_var($_GET['value'], FILTER_SANITIZE_NUMBER_FLOAT)) : 'Received other than float';
            $discount_codes = (isset($_GET['count'])) ? (filter_var($_GET['count'], FILTER_SANITIZE_NUMBER_INT)) : 'Received other than int';

            /* -------------------------------------------------------------
             * STEP 2: Generate and add to local database the discount codes
             * -------------------------------------------------------------
             */
            $generate_code = function() {
                return substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 1) . substr(str_shuffle('AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPpQqRrSsTtUuVvWwXxYyZz'), 0, 9);
            };

            $datetime = new DateTime();
            $start_date = $datetime->format('Y-m-d');
            $datetime->modify('+6 months');
            $expiration_date = $datetime->format('Y-m-d');

            for ($i = $discount_codes; $i > 0; $i--) {

                $code = $generate_code();
                $discount_codes_collection[] = $code;

                /* Discount type: Fixed value */
                if ($discount_type == 0) {

                    $this->db->query("
                                INSERT INTO " . DB_PREFIX . "coupon
                                SET name = 'Discount Code: RTG_FX',
                                    code = '{$code}',
                                    discount = '{$discount_value}',
                                    type = 'F',
                                    total = '0',
                                    logged = '0',
                                    shipping = '0',
                                    date_start = '{$start_date}',
                                    date_end = '{$expiration_date}',
                                    uses_total = '1',
                                    uses_customer = '1',
                                    status = '1',
                                    date_added = NOW()
                                ");

                    /* Discount type: Percentage */
                } elseif ($discount_type == 1) {

                    $this->db->query("
                                INSERT INTO " . DB_PREFIX . "coupon
                                SET name = 'Discount Code: RTG_PRCNT',
                                    code = '{$code}',
                                    discount = '{$discount_value}',
                                    type = 'P',
                                    total = '0',
                                    logged = '0',
                                    shipping = '0',
                                    date_start = '{$start_date}',
                                    date_end = '{$expiration_date}',
                                    uses_total = '1',
                                    uses_customer = '1',
                                    status = '1',
                                    date_added = NOW()
                                ");

                    /* Discount type: Free delivery */
                } elseif ($discount_type == 2) {

                    $this->db->query("
                                INSERT INTO " . DB_PREFIX . "coupon
                                SET name = 'Discount Code: RTG_SHIP',
                                    code = '{$code}',
                                    discount = '0',
                                    type = 'F',
                                    total = '0',
                                    logged = '0',
                                    shipping = '1',
                                    date_start = '{$start_date}',
                                    date_end = '{$expiration_date}',
                                    uses_total = '1',
                                    uses_customer = '1',
                                    status = '1',
                                    date_added = NOW()
                                ");
                }

            } // End generating discount codes


            /* -------------------------------------------------------------
             * STEP 3: Return the newly generated codes
             * -------------------------------------------------------------
             */
            if (isset($discount_codes_collection) && !empty($discount_codes_collection)) {

                /* Modify the header */
                header('Content-Type: application/json');

                /* Output the json */
                echo json_encode($discount_codes_collection);

            }


            /* -------------------------------------------------------------
             * STEP 4: Kill the script
             * -------------------------------------------------------------
             */
            die();

        } // End $_GET processing
        /* --- END API URL & DISCOUNT CODES GENERATOR  --- */



        /* ---------------------------------------------------------------------------------------------------------------------
         *
         * Start implementing Retargeting JS functions
         *
         * --------------------------------------------------------------------------------------------------------------------*/

        /* Small helpers [pre-data processing] */
        $data['cart_products'] = isset($this->session->data['cart']) ? $this->session->data['cart'] : false;
        $data['wishlist'] = !empty($this->session->data['wishlist']) ? $this->session->data['wishlist'] : false;
        $data['current_page'] = isset($this->request->get['route']) ? $this->request->get['route'] : false;
        $data['current_category'] = isset($this->request->get['path']) ? explode('_', $this->request->get['path']) : '';
        $data['count_categories'] = (count($data['current_category']) > 0) ? (count($data['current_category'])) : 0;
        $data['js_output'] = "/* --- START Retargeting --- */\n\n";
        /* --- END pre-data processing  --- */



        /*
         * setEmail
         */
        /* User is logged in, pull data from DB */
        if (isset($this->session->data['customer_id']) && !empty($this->session->data['customer_id'])) {
            $full_name = $this->customer->getFirstName() . $this->customer->getLastName();
            $email_address = $this->customer->getEmail();
            $phone_number = $this->customer->getTelephone();

            $data['js_output'] .= "
                                        var _ra = _ra || {};
                                        _ra.setEmailInfo = {
                                            'email': '{$email_address}',
                                            'name': '{$full_name}',
                                            'phone': '{$phone_number}'
                                        };
                                        
                                        if (_ra.ready !== undefined) {
                                            _ra.setEmail(_ra.setEmailInfo)
                                        }
                                    ";
        } else {
            /* Listen on entire site for input data & validate it */
            $data['setEmail'] = "
                                        /* -- setEmail -- */
                                        function checkEmail(email) {
                                            var regex = /^([a-zA-Z0-9_.+-])+\@(([a-zA-Z0-9-])+\.)+([a-zA-Z0-9]{2,9})+$/;
                                            return regex.test(email);
                                        };

                                        jQuery(document).ready(function($){
                                            $(\"{$data['retargeting_setEmail']}\").blur(function(){
                                                if ( checkEmail($(this).val()) ) {
                                                    _ra.setEmail({ 'email': $(this).val()});
                                                    console.log('setEmail fired!');
                                                }
                                            });
                                        });
                                        ";

            $data['js_output'] .= $data['setEmail'];           
        }
        /* --- END setEmail  --- */



        /*
         * sendCategory ✓
         */
        if ($data['current_page'] === 'product/category') {

            $category_id_parent = $data['current_category'][0];
            $category_info_parent = $this->model_catalog_category->getCategory($category_id_parent);

            $data['sendCategory'] = '
                                            /* -- sendCategory -- */
                                            ';
            $data['sendCategory'] = 'var _ra = _ra || {}; ';
            $data['sendCategory'] .= '_ra.sendCategoryInfo = {';

            /* We have a nested category */
            if (count($data['current_category']) > 1) {

                for ($i = count($data['current_category']) - 1; $i > 0; $i--) {
                    $category_id = $data['current_category'][$i];
                    $category_info = $this->model_catalog_category->getCategory($category_id);
                    $data['sendCategory'] .= "
                                                    'id': {$category_id},
                                                    'name': '{$category_info['name']}',
                                                    'parent': {$category_id_parent},
                                                    'category_breadcrumb': [
                                                    ";
                    break;
                }

                array_pop($data['current_category']);

                for ($i = count($data['current_category']) - 1; $i >= 0; $i--) {
                    $category_id = $data['current_category'][$i];
                    $category_info = $this->model_catalog_category->getCategory($category_id);

                    if ($i === 0) {
                        $data['sendCategory'] .= "{
                                                        'id': {$category_id_parent},
                                                        'name': '{$category_info_parent['name']}',
                                                        'parent': false
                                                        }
                                                        ";
                        break;
                    }

                    $data['sendCategory'] .= "{
                                                    'id': {$category_id},
                                                    'name': '{$category_info['name']}',
                                                    'parent': {$category_id_parent}
                                                    },
                                                    ";
                }

                $data['sendCategory'] .= "]";

                /* We have a single category */
            } else {

                $data['category_id'] = $data['current_category'][0];
                $data['category_info'] = $this->model_catalog_category->getCategory($data['category_id']);
                $data['sendCategory'] .= "
                                                'id': {$data['category_id']},
                                                'name': '{$data['category_info']['name']}',
                                                'parent': false,
                                                'category_breadcrumb': []
                                                ";
            }

            //reset($data['current_category']);

            $data['sendCategory'] .= '};';
            $data['sendCategory'] .= "
                                            if (_ra.ready !== undefined) {
                                                _ra.sendCategory(_ra.sendCategoryInfo);
                                            };
                                            ";

            /* Send to output */
            $data['js_output'] .= $data['sendCategory'];
        }
        /* --- END sendCategory  --- */



        /*
         * sendBrand ✓
         */
        if ($data['current_page'] === 'product/manufacturer/info') {

            /* Check if the current product is part of a brand */
            if (isset($this->request->get['manufacturer_id']) && !empty($this->request->get['manufacturer_id'])) {
                $data['brand_id'] = $this->request->get['manufacturer_id'];
                $data['brand_name'] = $this->model_catalog_manufacturer->getManufacturer($this->request->get['manufacturer_id']);
                $data['sendBrand'] = "var _ra = _ra || {};
                                            _ra.sendBrandInfo = {
                                                                'id': {$data['brand_id']},
                                                                'name': '{$data['brand_name']['name']}'
                                                                };

                                                                if (_ra.ready !== undefined) {
                                                                    _ra.sendBrand(_ra.sendBrandInfo);
                                                                };
                                            ";

                /* Send to output */
                $data['js_output'] .= $data['sendBrand'];
            }
        }
        /* --- END sendBrand  --- */

        

        /*
         * sendProduct
         * likeFacebook
         */
        if ($data['current_page'] === 'product/product') {

            $product_id = $this->request->get['product_id'];
            $product_url = $this->url->link('product/product', 'product_id=' . $product_id);
            $product_details = $this->model_catalog_product->getProduct($product_id);
            $product_categories = $this->db->query("SELECT * FROM " . DB_PREFIX . "product_to_category WHERE product_id = '" . (int)$product_id . "'");
            $product_categories = $product_categories->rows; // Get all the subcategories for this product. Reorder its numerical indexes to ease the breadcrumb logic

            /* Send the base info */
            $data['sendProduct'] = "
                                    var _ra = _ra || {}; _ra.sendProductInfo = {
                                    ";
            $data['sendProduct'] .= "
                                    'id': $product_id,
                                    'name': '{$product_details['name']}',
                                    'url': '{$product_url}',
                                    'img': '{$data['shop_url']}image/{$product_details['image']}',
                                    'price': '".round($this->tax->calculate($product_details['price'], $product_details['tax_class_id'], $this->config->get('config_tax')),2)."',
                                    'promo': '". (isset($product_details['special']) ? round($this->tax->calculate($product_details['special'],$product_details['tax_class_id'], $this->config->get('config_tax')),2) : 0) ."',
                                    'stock': ". (($product_details['quantity'] > 0) ? 1 : 0) .",
                                    ";

            /* Check if the product has a brand assigned */
            if (isset($product_details['manufacturer_id'])) {
                $data['sendProduct'] .= "
                                        'brand': {'id': {$product_details['manufacturer_id']}, 'name': '{$product_details['manufacturer']}'},
                                        ";
            } else {
                $data['sendProduct'] .= "
                                        'brand': false,
                                        ";
            }

            /* Check if the product has a category assigned */
            if (isset($product_categories) && !empty($product_categories)) {

                $product_cat = $this->model_catalog_product->getCategories($product_id);
                $product_cat_details = $this->model_catalog_category->getCategory($product_cat[0]['category_id']);

                // Resides in a parent category
                if (isset($product_cat_details['parent_id']) && ($product_cat_details['parent_id'] == 0)) {

                    $data['sendProduct'] .= "
                                            'category': {'id': {$product_cat_details['category_id']}, 'name': '{$product_cat_details['name']}', 'parent': false},
                                            'category_breadcrumb': []
                                            ";

                // Resides in a nested category (child -> go up until parent)
                } else {

                    $product_cat_details_parent = $this->model_catalog_category->getCategory($product_cat_details['parent_id']);

                    // Get the top level category
                    $data['sendProduct'] .= "
                                            'category': {'id': {$product_cat_details['category_id']}, 'name': '{$product_cat_details['name']}', 'parent': {$product_cat_details['parent_id']}},
                                            'category_breadcrumb': [{'id': {$product_cat_details_parent['category_id']}, 'name': '{$product_cat_details_parent['name']}', 'parent': false}]
                                            ";

                } // Close elseif

            } // Close check if product has categories assigned

            $data['sendProduct'] .= "};"; // Close _ra.sendProductInfo
            $data['sendProduct'] .= "
                                            if (_ra.ready !== undefined) {
                                                _ra.sendProduct(_ra.sendProductInfo);
                                            };
                                            ";
            $data['js_output'] .= $data['sendProduct'];



            $data['likeFacebook'] = "
                                        if (typeof FB != 'undefined') {
                                            FB.Event.subscribe('edge.create', function () {
                                                _ra.likeFacebook({$product_id});
                                            });
                                        };
                                    ";
            $data['js_output'] .= $data['likeFacebook'];
        }
        /* --- END sendProduct  --- */

        

        /*
         * addToWishList ✓
         */
        if (($data['wishlist'])) {

            /* Prevent notices */
            $this->session->data['retargeting_wishlist_product_id'] = (isset($this->session->data['retargeting_wishlist_product_id']) && !empty($this->session->data['retargeting_wishlist_product_id'])) ? $this->session->data['retargeting_wishlist_product_id'] : '';

            /* While pushing out an item from the WishList with a lower array index, OpenCart won't reset the numerical indexes, thus generating a notice. This fixes it */
            $data['wishlist'] = array_values($data['wishlist']);

            if ($this->session->data['retargeting_wishlist_product_id'] != ($data['wishlist'][count($data['wishlist']) - 1])) {
                /* Get the total number of products in WishList; push the last added product into Retargeting */
                for ($i = count($data['wishlist']) - 1; $i >= 0; $i--) {
                    $product_id_in_wishlist = $data['wishlist'][$i] ;
                    break;
                }

                $data['addToWishlist'] = "
                                            var _ra = _ra || {};
                                            _ra.addToWishlistInfo = {
                                                                    'product_id': {$product_id_in_wishlist}
                                                                    };

                                            if (_ra.ready !== undefined) {
                                                _ra.addToWishlist(_ra.addToWishlistInfo.product_id);
                                            };
                                            ";

                /* We need to send the addToWishList event one time only. */
                $this->session->data['retargeting_wishlist_product_id'] = $product_id_in_wishlist;

                $data['js_output'] .= $data['addToWishlist'];
            }
        }
        /* --- END addToWishList  --- */



        /*
         * commentOnProduct ✓
         */
        if ($data['current_page'] === 'product/product') {
            $commentOnProduct_product_info = $this->request->get['product_id'];
            $data['commentOnProduct'] = "
                                        /* -- commentOnProduct -- */
                                        jQuery(document).ready(function($){
                                            if ($(\"{$data['retargeting_commentOnProduct']}\").length > 0) {
                                                $(\"{$data['retargeting_commentOnProduct']}\").click(function(){
                                                    _ra.commentOnProduct({$commentOnProduct_product_info}, function() {console.log('commentOnProduct FIRED')});
                                                });
                                            }
                                        });
                                        ";

            $data['js_output'] .= $data['commentOnProduct'];
        }
        /* --- END commentOnProduct  --- */



        /*
         * mouseOverPrice ✓
         * clickImage ✓
         * likeFacebook ✓
         * setVariation ✓
         */
        if ($data['current_page'] === 'product/product') {
            $mouseOverPrice_product_id = $this->request->get['product_id'];
            $mouseOverPrice_product_info = $this->model_catalog_product->getProduct($mouseOverPrice_product_id);
            $mouseOverPrice_product_promo = (isset($mouseOverPrice_product_info['special'])) ? $mouseOverPrice_product_info['special'] : '0';

            $data['mouseOverPrice'] = "
                                            /* -- mouseOverPrice -- */
                                            jQuery(document).ready(function($) {
                                                if ($(\"{$data['retargeting_mouseOverPrice']}\").length > 0) {
                                                    $(\"{$data['retargeting_mouseOverPrice']}\").mouseover(function(){
                                                        _ra.mouseOverPrice({$mouseOverPrice_product_id}, {
                                                                                                        'price': {$mouseOverPrice_product_info['price']},
                                                                                                        'promo': {$mouseOverPrice_product_promo}
                                                                                                        }, function() {console.log('mouseOverPrice FIRED')}
                                                        );
                                                    });
                                                }
                                            });

                                            /* -- clickImage -- */
                                            jQuery(document).ready(function($) {
                                                if ($(\"{$data['retargeting_clickImage']}\").length > 0) {
                                                    $(\"{$data['retargeting_clickImage']}\").mouseover(function(){

                                                        _ra.clickImage({$mouseOverPrice_product_id}, function() {console.log('clickImage FIRED')});
                                                    });
                                                }
                                            });

                                            /* -- likeFacebook -- */
                                            jQuery(document).ready(function($) {
                                                if (typeof FB != 'undefined') {
                                                    FB.Event.subscribe('edge.create', function () {
                                                        _ra.likeFacebook({$this->request->get['product_id']});
                                                    });
                                                }
                                            });

                                            /* -- setVariation -- */
                                            jQuery(document).ready(function($){
                                                $(\"{$this->data['retargeting_setVariation']}\").click(function(){
                                                    if ( $(this).val() != undefined ) {
                                                        _ra.setVariation({$this->request->get['product_id']}, {
                                                            'code': '$(this).val()',
                                                            'details': {}
                                                        }, function() {
                                                            console.log('setVariation fired.');
                                                        });
                                                    }
                                                });
                                            });
                                            ";

            $data['js_output'] .= $data['mouseOverPrice'];
        }
        /* --- END mouseOverPrice, clickImage, likeFacebook, setVariation  --- */



        /*
         * mouseOverAddToCart ✓
         * addToCart [v1 - class/id listener] ✓
         */
        if ($data['current_page'] === 'product/product') {
            $mouseOverAddToCart_product_id = $this->request->get['product_id'];
            $mouseOverAddToCart_product_info = $this->model_catalog_product->getProduct($mouseOverAddToCart_product_id);
            $mouseOverAddToCart_product_promo = isset($mouseOverAddToCart_product_info['promo']) ? : 0;

            $data['mouseOverAddToCart'] = "
                                                /* -- mouseOverAddToCart & addToCart -- */
                                                jQuery(document).ready(function($){
                                                    if ($(\"{$data['retargeting_addToCart']}\").length > 0) {
                                                        /* -- mouseOverAddToCart -- */
                                                        $(\"{$data['retargeting_addToCart']}\").mouseover(function(){
                                                             _ra.mouseOverAddToCart({$mouseOverAddToCart_product_id}, function(){console.log('mouseOverAddToCart FIRED')});
                                                        });

                                                        /* -- addToCart -- */
                                                        $(\"{$data['retargeting_addToCart']}\").click(function(){
                                                            _ra.addToCart({$mouseOverAddToCart_product_id}, false, function(){console.log('addToCart FIRED!')});
                                                        });
                                                    }
                                                });
                                                ";

            $data['js_output'] .= $data['mouseOverAddToCart'];
        }
        /* --- END mouseOverAddToCart & addToCart[v1]  --- */


        /*
         * visitHelpPage ✓
         */
        if ($data['current_page'] === 'information/information') {
            $data['visitHelpPage'] = "
                                        /* -- visitHelpPage -- */
                                        var _ra = _ra || {};
                                        _ra.visitHelpPageInfo = {'visit' : true};
                                        if (_ra.ready !== undefined) {
                                            _ra.visitHelpPage();
                                        };
                                    ";
            $data['js_output'] .= $data['visitHelpPage'];
        }
        /* --- END visitHelpPage  --- */



        /*
         * checkoutIds ✓
         */
        $checkout_modules = array('checkout/checkout', 'checkout/simplecheckout', 'checkout/ajaxquickcheckout', 'checkout/ajaxcheckout', 'checkout/quickcheckout', 'checkout/onepagecheckout');
        if ((in_array($data['current_page'], $checkout_modules) && $data['cart_products']) || ($data['current_page'] === 'checkout/cart' && $data['cart_products'])) {
            $cart_products = $this->cart->getProducts(); // Use this instead of session
            $data['checkoutIds'] = "
                                        /* -- checkoutIds -- */
                                        var _ra = _ra || {};
                                        _ra.checkoutIdsInfo = [
                                    ";

            $i_products = count($cart_products);
            foreach ($cart_products as $item => $detail) {
                $i_products--;
                $data['checkoutIds'] .= ($i_products > 0) ? $detail['product_id'] . "," : $detail['product_id'];
            }

            $data['checkoutIds'] .= "
                                            ];
                                            ";
            $data['checkoutIds'] .= "
                                            if (_ra.ready !== undefined) {
                                                _ra.checkoutIds(_ra.checkoutIdsInfo);
                                            };
                                            ";

            $data['js_output'] .= $data['checkoutIds'];
        }
        /* --- END checkoutIds  --- */



        /*
         * saveOrder ✓
         * 
         * via pre.order.add event
         */
        if (
            (isset($this->session->data['retargeting_pre_order_add']) && !empty($this->session->data['retargeting_pre_order_add']))
            ||
            (isset($this->session->data['retargeting_post_order_add']) && !empty($this->session->data['retargeting_post_order_add']))
                                                                                                                                    ) {
            
            $data['order_id'] = $this->session->data['retargeting_post_order_add'];
            $data['order_data'] = $this->model_checkout_order->getOrder($data['order_id']);

            $order_no = $data['order_data']['order_id'];
            $lastname = $data['order_data']['lastname'];
            $firstname = $data['order_data']['firstname'];
            $email = $data['order_data']['email'];
            $phone = $data['order_data']['telephone'];
            $state = $data['order_data']['shipping_country'];
            $city = $data['order_data']['shipping_city'];
            $address = $data['order_data']['shipping_address_1'];

            $discount_code = isset($this->session->data['retargeting_discount_code']) ? $this->session->data['retargeting_discount_code'] : 0;
            $total_discount_value = 0;
            $shipping_value = 0;
            $total_order_value = $data['order_data']['total'];;

            // Based on order id, grab the ordered products
            $order_product_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int)$data['order_id'] . "'");
            $data['order_product_query'] = $order_product_query;

            $data['saveOrder'] = "
                                        var _ra = _ra || {};
                                        _ra.saveOrderInfo = {
                                            'order_no': {$order_no},
                                            'lastname': '{$lastname}',
                                            'firstname': '{$firstname}',
                                            'email': '{$email}',
                                            'phone': '{$phone}',
                                            'state': '{$state}',
                                            'city': '{$city}',
                                            'address': '{$address}',
                                            'discount_code': '{$discount_code}',
                                            'discount': {$total_discount_value},
                                            'shipping': {$shipping_value},
                                            'total': {$total_order_value}
                                        };
                                        ";

            /* -------------------------------------- */
            $data['saveOrder'] .= "_ra.saveOrderProducts = [";
            for ($i = count($order_product_query->rows) - 1; $i >= 0; $i--) {
                if ($i == 0) {
                    $data['saveOrder'] .= "{
                                                'id': {$order_product_query->rows[$i]['product_id']},
                                                'quantity': {$order_product_query->rows[$i]['quantity']},
                                                'price': {$order_product_query->rows[$i]['price']},
                                                'variation_code': ''
                                                }";
                    break;
                }
                $data['saveOrder'] .= "{
                                            'id': {$order_product_query->rows[$i]['product_id']},
                                            'quantity': {$order_product_query->rows[$i]['quantity']},
                                            'price': {$order_product_query->rows[$i]['price']},
                                            'variation_code': ''
                                            },";
            }
            $data['saveOrder'] .= "];";
            /* -------------------------------------- */

            $data['saveOrder'] .= "
                                        if( _ra.ready !== undefined ) {
                                            _ra.saveOrder(_ra.saveOrderInfo, _ra.saveOrderProducts);
                                        }";
            $data['js_output'] .= $data['saveOrder'];
            
            /*
            * REST API Save Order
            */

            $apiKey = $this->config->get('retargeting_apikey');
            $token = $this->config->get('retargeting_token');

            if($apiKey && $token && $apiKey != '' && $token != ''){
                $orderInfo = array(
                    'order_no' => $order_no,
                    'lastname' => $lastname,
                    'firstname' => $firstname,
                    'email' => $email,
                    'phone' => $phone,
                    'state' => $state,
                    'city' => $city,
                    'address' => $address,
                    'discount_code' => $discount_code,
                    'discount' => $total_discount_value,
                    'shipping' => $shipping_value,
                    'total' => $total_order_value
                );

                $orderProducts = array();

                foreach($order_product_query->rows as $orderedProduct) {
                    $orderProducts[] = array(
                        'id' => $orderedProduct['product_id'],
                        'quantity'=> $orderedProduct['quantity'],
                        'price'=> $orderedProduct['price'],
                        'variation_code'=> ''
                    );
                }

                $orderClient = new Retargeting_REST_API_Client($apiKey, $token);
                $orderClient->setResponseFormat("json");
                $orderClient->setDecoding(false);
                $response = $orderClient->order->save($orderInfo,$orderProducts);
            }
            
            unset($this->session->data['retargeting_pre_order_add']);
            unset($this->session->data['retargeting_post_order_add']);
        }


        /* ---------------------------------------------------------------------------------------------------------------------
         * Set the template path for our module & load the View
         * ---------------------------------------------------------------------------------------------------------------------
         */
        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/module/retargeting.tpl')) {
            return $this->load->view($this->config->get('config_template') . '/template/module/retargeting.tpl', $data);
        } else {
            return $this->load->view('/module/retargeting.tpl', $data);
        }
	}


    
    /* ---------------------------------------------------------------------------------------------------------------------
     * Event: pre.order.add
     * 
     * Called: After the order has been launched && before unset($data)
     * Used for: saveOrder js
     * Returns: (array)$data
     * ---------------------------------------------------------------------------------------------------------------------
     */
    public function pre_order_add($data) {

        $this->session->data['retargeting_pre_order_add'] = $data;

    }



    /* ---------------------------------------------------------------------------------------------------------------------
     * Event: post.order.add
     * 
     * Called: After the order has been launched
     * Returns: (int) $order_id
     * Used for: saveOrder js
     * ---------------------------------------------------------------------------------------------------------------------
     */
    public function post_order_add($order_id) {

        $this->session->data['retargeting_post_order_add'] = $order_id;

    }




}
