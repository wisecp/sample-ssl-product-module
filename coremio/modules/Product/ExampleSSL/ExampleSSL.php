<?php
    class ExampleSSL {
        public $api                = false;
        public $config             = [];
        public $lang               = [];
        public  $error             = NULL;
        private $order             = [];
        private $user              = [];
        private $product           = [];
        private $_temp             = [];

        function __construct(){

            $this->config   = Modules::Config("Product",__CLASS__);
            $this->lang     = Modules::Lang("Product",__CLASS__);

            if(!class_exists("ExampleSSL_API")) include __DIR__.DS."api.php";

            $username   = $this->config["settings"]["username"];
            $password   = $this->config["settings"]["password"];
            $password   = Crypt::decode($password,Config::get("crypt/system"));
            $tmode      = (bool) $this->config["settings"]["test-mode"];

            $this->api =  new ExampleSSL_API($tmode);

            $this->api->set_credentials($username,$password);
        }

        public function set_order($order=[]){
            $this->order =  $order;
            Helper::Load(["Products","User"]);
            $this->product = Products::get($order["type"],$order["product_id"]);
            $this->user    = User::getData($order["owner_id"],"id,name,surname,full_name,company_name,email,phone,lang","array");
        }

        private function setConfig($username,$password,$tmode){
            $this->config["settings"]["username"] = $username;
            $this->config["settings"]["password"] = $password;
            $this->config["settings"]["test-mode"] = $tmode;

            $this->api =  new ExampleSSL_API($tmode);

            $this->api->set_credentials($username,$password);

            return $this;
        }

        public function testConnection(){
            if(!$this->api->connect()){
                $this->error = $this->api->error;
                return false;
            }

            return true;
        }

        public function use_method($method=''){
            if($method == "apply_changes") return $this->ac_edit_order_params();
            return true;
        }

        public function run_action($data=[]){
            if($data["command"] == "checking-ssl-enroll") return $this->checking_enroll($data);
            return true;
        }

        public function checking_enroll($data=[]){
            $u_lang = Modules::Lang("Product",__CLASS__,$this->user["lang"]);

            if(isset($this->order["options"]["config"]["order_id"]) && $this->order["options"]["config"]["order_id"]){
                $certificate   = $this->get_cert_details();

                if(!$certificate && $this->error !== "Certificate not enrolled"){
                    $this->error = $this->api->error;
                    return false;
                }

                if(!$certificate) return "continue";

                $folder         = ROOT_DIR.RESOURCE_DIR."uploads".DS."orders".DS;
                $name           = Utility::generate_hash(20,false,'ld').".txt";
                $file_name      = $folder.$name;

                $save           = FileManager::file_write($file_name,$certificate);
                if(!$save){
                    $this->error = "Path: ".$file_name." failed to open stream: No such file or directory";
                    return false;
                }

                $options        = $this->order["options"];
                $options["delivery_file"] = $name;
                $options["delivery_file_button_title"] = $u_lang["delivery_file_button_name"];
                $options["delivery_title_name"] = $u_lang["delivery_title"];
                $options["delivery_title_description"] = '';

                if(isset($options["checking-ssl-enroll"])) unset($options["checking-ssl-enroll"]);

                $this->order["options"] = $options;
                Orders::set($this->order["id"],['options' => Utility::jencode($this->order["options"])]);
            }
            return true;
        }

        public function edit_order_params(){
            $options        = $this->order["options"];
            if(!isset($options["creation_info"])) $options["creation_info"] = [];
            if(!isset($options["config"])) $options["config"] = [];
            $creation_info  = (array) Filter::POST("creation_info");
            $config         = (array) Filter::POST("config");
            $csr_code       = (string) Filter::POST("csr-code");
            $vrf_email      = (string) Filter::init("POST/verification-email","letters");
            $vrf_email_ntf  = (int) Filter::init("POST/verification-email-notification","numbers");
            $setup          = (int) Filter::init("POST/setup","numbers");
            $reissue        = (int) Filter::init("POST/reissue","numbers");
            $additional_ds  = Filter::POST("additional-domains");
            $dcv_method     = (string) Filter::init("POST/dcv-method","letters");


            if($config) $options["config"] = array_replace_recursive($options["config"],$config);
            $options["creation_info"] = array_replace_recursive($options["creation_info"],$creation_info);

            if(!isset($options["config"]["order_id"]) || !$options["config"]["order_id"]) unset($options["config"]);

            if($csr_code) $options["csr-code"] = $csr_code;
            if($vrf_email) $options["verification-email"] = $vrf_email ? $vrf_email : "admin";
            if($dcv_method) $options["dcv-method"] = !$dcv_method ? "email" : $dcv_method;


            if($additional_ds){
                $san_domains    = [];
                foreach($additional_ds AS $row){
                    $san_domain = Filter::domain(isset($row["domain"]) ? $row["domain"] : '');
                    $san_dcv_m  = Filter::letters(isset($row["dcv-method"]) ? $row["dcv-method"] : 'email');
                    $san_vf_eml = Filter::email(isset($row["verification-email"]) ? $row["verification-email"] : 'admin');
                    if($san_domain && strlen($san_domain)>= 5){
                        $san_domains[] = [
                            'domain'                => $san_domain,
                            'dcv-method'            => $san_dcv_m,
                            'verification-email'    => $san_vf_eml,
                        ];
                    }
                }
                if($san_domains) $options["additional-domains"] = $san_domains;
                elseif(isset($options["additional-domains"])) unset($options["additional-domains"]);
            }

            $old_options            = $this->order["options"];
            if(!isset($old_options["dcv-method"])) $old_options["dcv-method"] = "email";

            $this->order["options"] = $options;

            $established            = false;
            if(isset($options["config"]["order_id"]) && $options["config"]["order_id"]) $established = true;

            if($established){

                $cert_details            = $this->get_cert_details();

                if($cert_details && $reissue){
                    $reissue  = $this->reissue();
                    if(!$reissue) return false;
                }
                elseif(!$cert_details && isset($old_options["verification-email"]) && $old_options["verification-email"] !== $vrf_email){
                    $change  = $this->change_verification_email();
                    if(!$change) return false;
                }
                elseif(!$cert_details && isset($old_options["dcv-method"]) && $old_options["dcv-method"] !== $dcv_method){
                    $change  = $this->change_verification_email();
                    if(!$change) return false;
                }
                elseif(!$cert_details && $vrf_email_ntf){
                    $change  = $this->resend_verification_email();
                    if(!$change) return false;
                }

                if($revalidate = Filter::POST("revalidate")){
                    if(is_array($revalidate)){
                        foreach($revalidate AS $domain){
                            $domain = Filter::domain($domain);
                            $apply  = $this->revalidate($domain);
                            if(!$apply) return false;
                        }
                    }
                }

            }


            if($setup){
                $setup  = $this->create();
                if(!$setup) return false;
                if($setup && is_array($setup))
                    $this->order["options"] = array_replace_recursive($this->order["options"],$setup);
            }
            return $this->order["options"];
        }

        public function ac_edit_order_params(){
            $options        = $this->order["options"];
            if(!isset($options["creation_info"])) $options["creation_info"] = [];
            if(!isset($options["config"])) $options["config"] = [];

            $csr_code       = (string) Filter::POST("csr-code");
            $vrf_email      = (string) Filter::POST("verification-email");
            $vrf_email_ntf  = (int) Filter::init("POST/verification-email-notification","numbers");
            $reissue        = (int) Filter::init("POST/reissue","numbers");
            $additional_ds  = Filter::POST("additional-domains");
            $dcv_method     = (string) Filter::init("POST/dcv-method","letters");

            if($csr_code) $options["csr-code"] = $csr_code;
            if($vrf_email) $options["verification-email"] = $vrf_email ? $vrf_email : "admin";
            if($dcv_method) $options["dcv-method"] = !$dcv_method ? "email" : $dcv_method;

            if($additional_ds){
                $san_domains    = [];
                foreach($additional_ds AS $row){
                    $san_domain = Filter::domain(isset($row["domain"]) ? $row["domain"] : '');
                    $san_dcv_m  = Filter::letters(isset($row["dcv-method"]) ? $row["dcv-method"] : 'email');
                    $san_vf_eml = Filter::email(isset($row["verification-email"]) ? $row["verification-email"] : 'admin');
                    if($san_domain && strlen($san_domain)>= 5){
                        $san_domains[] = [
                            'domain'                => $san_domain,
                            'dcv-method'            => $san_dcv_m,
                            'verification-email'    => $san_vf_eml,
                        ];
                    }
                }
                if($san_domains) $options["additional-domains"] = $san_domains;
                elseif(isset($options["additional-domains"])) unset($options["additional-domains"]);
            }

            $old_options            = $this->order["options"];
            $this->order["options"] = $options;

            $established            = false;
            if(isset($options["config"]["order_id"]) && $options["config"]["order_id"]) $established = true;

            if($established){

                $cert_details            = $this->get_cert_details();

                if($cert_details && $reissue){
                    $reissue  = $this->reissue();
                    if(!$reissue)
                        die(Utility::jencode([
                            'status' => "error",
                            'message' => __("website/account_products/error2",['{error}' => $this->error]),
                        ]));

                }
                elseif(!$cert_details && isset($old_options["verification-email"]) && $old_options["verification-email"] !== $vrf_email){
                    $change  = $this->change_verification_email();
                    if(!$change)
                        die(Utility::jencode([
                            'status' => "error",
                            'message' => __("website/account_products/error2",['{error}' => $this->error]),
                        ]));
                }
                elseif(!$cert_details && isset($old_options["dcv-method"]) && $old_options["dcv-method"] !== $dcv_method){
                    $change  = $this->change_verification_email();
                    if(!$change)
                        die(Utility::jencode([
                            'status' => "error",
                            'message' => __("website/account_products/error2",['{error}' => $this->error]),
                        ]));
                }
                elseif(!$cert_details && $vrf_email_ntf){
                    $change  = $this->resend_verification_email();
                    if(!$change)
                        die(Utility::jencode([
                            'status' => "error",
                            'message' => __("website/account_products/error2",['{error}' => $this->error]),
                        ]));
                }

                if($revalidate = Filter::POST("revalidate")){
                    if(is_array($revalidate)){
                        foreach($revalidate AS $domain){
                            $domain = Filter::domain($domain);
                            $apply  = $this->revalidate($domain);
                            if(!$apply)
                                die(Utility::jencode([
                                    'status' => "error",
                                    'message' => __("website/account_products/error2",['{error}' => $this->error]),
                                ]));
                        }
                    }
                }
            }

            Orders::set($this->order["id"],['options' => Utility::jencode($this->order["options"])]);

            echo Utility::jencode([
                'status' => "successful",
                'message' => $this->lang["success4"],
                'redirect' => Controllers::$init->CRLink("ac-ps-product",[$this->order["id"]]),
            ]);


            return true;
        }

        public function create($params=[]){
            if(!$params) $params = $this->order["options"];
            $domain     = isset($params["domain"]) ? $params["domain"] : false;
            if(!$domain){
                $this->error = $this->lang["error6"];
                return false;
            }

            if(!isset($params["creation_info"]["product-id"]) || !$params["creation_info"]["product-id"]){
                $this->error = $this->lang["error8"];
                return false;
            }

            if(!isset($params["csr-code"])){
                $this->error = $this->lang["error5"];
                return false;
            }

            $months                 = 12;
            $dcv_method             = "email";
            $additional_sans        = 0;
            $configurable_options   = [];
            $set_addons             = [];
            $additional_domains     = $this->get_additional_domains();


            if($this->order["period"] == "month")
                $months = $this->order["period_time"];
            elseif($this->order["period"] == "year")
                $months = ((int) $this->order["period_time"]) * 12;

            if(isset($params["dcv-method"])) $dcv_method = $params["dcv-method"];
            if(!isset($params["verification-email"])) $params["verification-email"] = "admin";


            if($this->order["status"] == "inprocess"){
                if($addons = Orders::addons($this->order["id"])){
                    $lang   = $this->user["lang"];
                    foreach($addons AS $addon){
                        if($gAddon = Products::addon($addon["addon_id"],$lang)){
                            if($gAddon["options"]){
                                if($gAddon["type"] == "quantity"){
                                    $addon_v    = $addon["option_name"];
                                    $addon_v    = explode("x",$addon_v);
                                    $addon_v    = (int) trim($addon_v[0]);
                                }else
                                    $addon_v        = 0;
                                foreach($gAddon["options"] AS $option){
                                    if($option["id"] == $addon["option_id"]){
                                        if(isset($option["module"]) && $option["module"]){
                                            if(isset($option["module"][__CLASS__])){
                                                $set_addons[] = $addon["id"];
                                                $c_options = $option["module"][__CLASS__]["configurable"];
                                                foreach($c_options AS $k=>$v) if($addon_v) $c_options[$k] = $addon_v;
                                                $configurable_options = array_replace_recursive($configurable_options,$c_options);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if(isset($configurable_options["sans_count"])) $additional_sans = $configurable_options["sans_count"];

            $dns_names              = '';
            $approver_emails        = '';

            if($additional_domains){
                $dns_names          = [];
                $approver_emails    = [];

                foreach($additional_domains AS $row){
                    if(isset($row["dcv-method"])){
                        if($row["dcv-method"] == "email") $approver_emails[] = $row["verification-email"]."@".$row["domain"];
                        else $approver_emails[] = $row["dcv-method"];
                    }
                    $dns_names[] = $row["domain"];
                }
                $dns_names       = implode(",",$dns_names);
                $approver_emails = implode(",",$approver_emails);
            }

            $webserver_type         = isset($params["server_type"]) ? $params["server_type"] : '-1';

            $fields     = [
                'product_id'        => $params["creation_info"]["product-id"],
                'period'            => $months,
                'server_count'      => "-1",
                'csr'               => $params["csr-code"],
                'approver_email'    => $params["verification-email"]."@".$domain,
                'webserver_type'    => $webserver_type,
                'approver_emails'   => $approver_emails,
                'dns_names'         => $dns_names,
                'dcv_method'        => $dcv_method,
            ];


            $get_address                = AddressManager::getAddress(0,$this->user["id"]);

            $contact_types          = ['admin','tech'];
            foreach($contact_types AS $type){
                $fields[$type.'_firstname'] = $this->user["name"];
                $fields[$type.'_lastname'] = $this->user["surname"];
                $fields[$type.'_organization'] = $this->user["company_name"];
                $fields[$type.'_addressline1'] = isset($get_address["address"]) ? $get_address["address"] : '';
                $fields[$type.'_phone'] = $this->user["phone"] ? "+".$this->user["phone"] : '';
                $fields[$type.'_title'] = 'Mr.';
                $fields[$type.'_email'] = $this->user["email"];
                $fields[$type.'_city']  = isset($get_address["city"]) ? $get_address["city"] : '';
                $fields[$type.'_country']  = isset($get_address["country_code"]) ? $get_address["country_code"] : '';
                $fields[$type.'_fax'] = '';
                $fields[$type.'_postalcode'] = isset($get_address["zipcode"]) ? $get_address["zipcode"] : '';
                $fields[$type.'_region']     = isset($get_address["counti"]) ? $get_address["counti"] : '';
            }

            $fields['org_name']         = $this->user["company_name"];
            $fields['org_division']     = '';
            $fields['org_duns']         = '';
            $fields['org_addressline1'] = isset($get_address["address"]) ? $get_address["address"] : '';
            $fields['org_city']         = isset($get_address["city"]) ? $get_address["city"] : '';
            $fields['org_country']      = isset($get_address["country_code"]) ? $get_address["country_code"] : '';
            $fields['org_fax']          = '';
            $fields['org_phone']        = $this->user["phone"] ? "+".$this->user["phone"] : '';
            $fields['org_postalcode']   = isset($get_address["zipcode"]) ? $get_address["zipcode"] : '';
            $fields['org_region']       = isset($get_address["counti"]) ? $get_address["counti"] : '';

            if(isset($params["_config"])){
                $orderID = $params["_config"]["order_id"];
            }
            else{
                $result                 = $this->api->addSSLOrder($fields);

                if(!$result){
                    $this->error = $this->api->error;
                    return false;
                }

                $orderID = $result["order_id"];
                if(!$orderID){
                    $this->error = "Unable to obtain Order-ID";
                    return false;
                }

                if($set_addons) foreach($set_addons AS $addon)
                    Orders::set_addon($addon,['status' => "active",'unread' => 1]);
            }

            $u_lang = Modules::Lang("Product",__CLASS__,$this->user["lang"]);

            $return_params = [
                'additional-domains'  => $additional_domains,
                'delivery_title_name' => $u_lang["delivery_title"],
                'delivery_title_description' => $u_lang["delivery_description"],
                'config'              => ['order_id' => $orderID],
            ];

            if($additional_sans){
                $result                 = $this->api->addSSLSANOrder([
                    'order_id'          => $orderID,
                    'count'             => $additional_sans,
                ]);
                if(!$result){
                    $this->error = $this->api->error;
                    $_config = $return_params["config"];
                    unset($return_params["config"]);
                    $return_params["_config"] = $_config;
                    $return_params["status"] = "inprocess";
                    return $return_params;
                }
            }

            if(isset($result["order_status"]) && $result["order_status"] != "active"){
                $this->error = "Order status not active";
                $return_params["status"] = "inprocess";
                return $return_params;
            }

            if(!class_exists("Events")) Helper::Load(["Events"]);
            Events::add_scheduled_operation([
                'owner'             => "order",
                'owner_id'          => $this->order["id"],
                'name'              => "run-action-for-order-module",
                'period'            => 'minute',
                'time'              => 5,
                'module'            => __CLASS__,
                'command'           => "checking-ssl-enroll",
            ]);

            return $return_params;
        }

        public function extend($data=[]){

            if(isset($this->order["options"]["config"]["order_id"])){
                $params     = $this->order["options"];

                $domain     = isset($params["domain"]) ? $params["domain"] : false;

                if(!isset($params["creation_info"]["product-id"]) || !$params["creation_info"]["product-id"]){
                    $this->error = $this->lang["error8"];
                    return false;
                }

                if(!isset($params["csr-code"]) || !$params["verification-email"]){
                    $this->error = $this->lang["error5"];
                    return false;
                }

                $months = 12;

                if($data["period"] == "month") $months = $data["time"];
                elseif($data["period"] == "year") $months = ((int) $data["time"]) * 12;

                $fields     = [
                    'product_id'        => $params["creation_info"]["product-id"],
                    'period'            => $months,
                    'server_count'      => "-1",
                    'csr'               => $params["csr-code"],
                    'approver_email'    => $params["verification-email"]."@".$domain,
                    'webserver_type'    => "-1",
                    'approver_emails'   => '',
                    'dns_names'         => '',
                    'dcv_method'        => "email",
                ];


                $get_address                = AddressManager::getAddress(0,$this->user["id"]);

                $contact_types          = ['admin','tech'];
                foreach($contact_types AS $type){
                    $fields[$type.'_firstname'] = $this->user["name"];
                    $fields[$type.'_lastname'] = $this->user["surname"];
                    $fields[$type.'_organization'] = $this->user["company_name"];
                    $fields[$type.'_addressline1'] = isset($get_address["address"]) ? $get_address["address"] : '';
                    $fields[$type.'_phone'] = $this->user["phone"] ? "+".$this->user["phone"] : '';
                    $fields[$type.'_title'] = 'Mr.';
                    $fields[$type.'_email'] = $this->user["email"];
                    $fields[$type.'_city']  = isset($get_address["city"]) ? $get_address["city"] : '';
                    $fields[$type.'_country']  = isset($get_address["country_code"]) ? $get_address["country_code"] : '';
                    $fields[$type.'_fax'] = '';
                    $fields[$type.'_postalcode'] = isset($get_address["zipcode"]) ? $get_address["zipcode"] : '';
                    $fields[$type.'_region']     = isset($get_address["counti"]) ? $get_address["counti"] : '';
                }

                $fields['org_name']         = $this->user["company_name"];
                $fields['org_division']     = '';
                $fields['org_duns']         = '';
                $fields['org_addressline1'] = isset($get_address["address"]) ? $get_address["address"] : '';
                $fields['org_city']         = isset($get_address["city"]) ? $get_address["city"] : '';
                $fields['org_country']      = isset($get_address["country_code"]) ? $get_address["country_code"] : '';
                $fields['org_fax']          = '';
                $fields['org_phone']        = $this->user["phone"] ? "+".$this->user["phone"] : '';
                $fields['org_postalcode']   = isset($get_address["zipcode"]) ? $get_address["zipcode"] : '';
                $fields['org_region']       = isset($get_address["counti"]) ? $get_address["counti"] : '';

                $result                 = $this->api->addSSLRenewOrder($fields);

                if(!$result){
                    $this->error = $this->api->error;
                    return false;
                }

                $orderID = $result["order_id"];
                if(!$orderID){
                    $this->error = "Unable to obtain Order-ID";
                    return false;
                }

                if(!class_exists("Events")) Helper::Load(["Events"]);
                Events::add_scheduled_operation([
                    'owner'             => "order",
                    'owner_id'          => $this->order["id"],
                    'name'              => "run-action-for-order-module",
                    'period'            => 'minute',
                    'time'              => 5,
                    'module'            => __CLASS__,
                    'command'           => "checking-ssl-enroll",
                ]);

                $u_lang = Modules::Lang("Product",__CLASS__,$this->user["lang"]);

                $opt        = $this->order["options"];
                $opt['delivery_title_name'] = $u_lang["delivery_title"];
                $opt['delivery_title_description'] = $u_lang["delivery_description"];
                $opt['config'] = ['order_id' => $orderID];
                Helper::Load(["Orders"]);
                Orders::set($this->order["id"],['options' => Utility::jencode($opt)]);

            }
            return true;
        }

        public function delete(){
            if(isset($this->order["options"]["config"]["order_id"])){
                $order_id   = $this->order["options"]["config"]["order_id"];
                $result     = $this->api->cancelSSLOrder($order_id);
                if(!$result){
                    $this->error = $this->api->error;
                    return false;
                }
            }
            return true;
        }

        public function get_products(){
            $list   = $this->api->getAllProducts();

            if(!$list && $this->api->error){
                $this->error = $this->api->error;
                return false;
            }

            if(isset($list["products"]) && $list["products"]) $list = $list["products"];

            $return     = [];

            if($list) foreach($list AS $item) $return[$item["id"]] = $item["name"];
            return $return;
        }

        public function get_details($order_id=0){
            if(!$order_id && isset($this->order["options"]["config"]["order_id"]) && $this->order["options"]["config"]["order_id"])
                $order_id = $this->order["options"]["config"]["order_id"];

            if(isset($this->_temp["get_details"][$order_id])) return $this->_temp["get_details"][$order_id];

            $response   = $this->api->getOrderStatus($order_id);
            if(!$response){
                $this->error = $this->api->error;
                return false;
            }

            $this->_temp["get_details"][$order_id] = $response;

            if($response["status"] == "cancelled"){
                $this->error = "Service has been canceled.";
                return false;
            }

            return $response;
        }

        public function get_cert_details($order_id=0){
            if(!$order_id && isset($this->order["options"]["config"]["order_id"]) && $this->order["options"]["config"]["order_id"])
                $order_id = $this->order["options"]["config"]["order_id"];

            $response   = $this->get_details($order_id);
            if(!$response) return false;

            $certificate = $response["crt_code"];

            if(!$certificate || $certificate == "-----BEGIN CERTIFICATE-----null-----END CERTIFICATE-----" || $certificate == "Empty"){
                $this->error = "Certificate not enrolled";
                return false;
            }

            return $certificate;
        }




        public function change_verification_email($params=[]){
            if(!$params) $params = $this->order["options"];

            $order_id                   = $params["config"]["order_id"];

            $approver_email             = isset($params["dcv-method"]) ? $params["dcv-method"] : 'email';

            if($approver_email == "email") $approver_email = $params["verification-email"]."@".$params["domain"];

            $fields                     = [
                'approver_email'        => $approver_email,
            ];

            $result                         = $this->api->changeValidationEmail($order_id,$fields);

            if(!$result){
                $this->error = $this->api->error;
                return false;
            }

            return true;
        }

        public function resend_verification_email($params=[]){
            if(!$params) $params = $this->order["options"];

            $order_id                   = $params["config"]["order_id"];

            $result                         = $this->api->resendValidationEmail($order_id);

            if(!$result){
                $this->error = $this->api->error;
                return false;
            }

            return true;
        }

        public function reissue($params=[]){
            if(!$params) $params = $this->order["options"];

            $order_id                   = $params["config"]["order_id"];
            $domain                     = $params["domain"];
            $additional_domains         = $this->get_additional_domains();
            $dcv_method                 = "email";


            if(isset($params["dcv-method"])) $dcv_method = $params["dcv-method"];

            $dns_names              = '';
            $approver_emails        = '';

            if($additional_domains){
                $dns_names          = [];
                $approver_emails    = [];

                foreach($additional_domains AS $row){
                    if(isset($row["dcv-method"])){
                        if($row["dcv-method"] == "email") $approver_emails[] = $row["verification-email"]."@".$row["domain"];
                        else $approver_emails[] = $row["dcv-method"];
                    }
                    $dns_names[] = $row["domain"];
                }
                $dns_names       = implode(",",$dns_names);
                $approver_emails = implode(",",$approver_emails);
            }

            $webserver_type         = isset($params["server_type"]) ? $params["server_type"] : '-1';

            $email                      = $params["verification-email"]."@".$domain;

            $fields                     = [
                'csr'                       => $params["csr-code"],
                'approver_email'             => $email,
                'approver_emails'            => $approver_emails,
                'dns_names'                  => $dns_names,
                'webserver_type'             => $webserver_type,
                'dcv_method'                 => $dcv_method,
            ];

            $result                         = $this->api->reissueSSLOrder($order_id,$fields);

            if(!$result){
                $this->error = $this->api->error;
                return false;
            }

            if(!class_exists("Events")) Helper::Load(["Events"]);
            Events::add_scheduled_operation([
                'owner'             => "order",
                'owner_id'          => $this->order["id"],
                'name'              => "run-action-for-order-module",
                'period'            => 'minute',
                'time'              => 10,
                'module'            => __CLASS__,
                'command'           => "checking-ssl-enroll",
            ]);

            $u_lang = Modules::Lang("Product",__CLASS__,$this->user["lang"]);

            $options        = $this->order["options"];
            if(isset($options["delivery_file"])) unset($options["delivery_file"]);
            if(isset($options["delivery_file_button_title"])) unset($options["delivery_file_button_title"]);
            $options["delivery_title_name"] = $u_lang["delivery_title"];
            $options["delivery_title_description"] = $u_lang["delivery_description"];
            $options["config"] = ['order_id' => $result["order_id"]];
            $this->order["options"] = $options;

            return true;
        }

        public function revalidate($domain=''){
            $params = $this->order["options"];
            if(!$domain) $domain = $params["domain"];

            $order_id                   = $params["config"]["order_id"];
            $result                     = $this->api->Revalidate($order_id,$domain);

            if(!$result){
                $this->error = $this->api->error;
                return false;
            }

            return true;
        }

        public function get_additional_sans_count(){
            $sans_count = 0;
            if($addons = Orders::addons($this->order["id"])){
                $lang   = $this->user["lang"];
                foreach($addons AS $addon){
                    if($gAddon = Products::addon($addon["addon_id"],$lang)){
                        if($gAddon["options"]){
                            if($gAddon["type"] == "quantity"){
                                $addon_v    = $addon["option_name"];
                                $addon_v    = explode("x",$addon_v);
                                $addon_v    = (int) trim($addon_v[0]);
                            }else
                                $addon_v        = 0;
                            foreach($gAddon["options"] AS $option){
                                if($option["id"] == $addon["option_id"]){
                                    if(isset($option["module"]) && $option["module"]){
                                        if(isset($option["module"][__CLASS__])){
                                            $set_addons[] = $addon["id"];
                                            $c_options = $option["module"][__CLASS__]["configurable"];
                                            foreach($c_options AS $k=>$v) if($addon_v) $c_options[$k] = $addon_v;
                                            if(isset($c_options["sans_count"]) && $c_options["sans_count"]){
                                                $sans_count = $c_options["sans_count"];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $sans_count;
        }

        public function get_additional_domains($options=[]){
            if(!$options) $options = $this->order["options"];
            $domains        = [];
            if(isset($options["additional-domains"]) && $options["additional-domains"])
                $domains = $options["additional-domains"];
            if(is_array($domains)) return $domains;
            $parse          = explode(EOL,$domains);
            $domains        = [];

            if($parse){
                foreach($parse AS $row){
                    $parse_x    = explode(":",$row);
                    $domain     = isset($parse_x[0]) ? $parse_x[0] : '';
                    $dcv_method = Filter::letters(isset($parse_x[1]) ? $parse_x[1] : 'email');
                    $vf_email   = Filter::email(isset($parse_x[2]) ? $parse_x[2] : 'admin');

                    if($domain)
                        $domains[] = [
                            'domain'     => $domain,
                            'dcv-method' => $dcv_method,
                            'verification-email' => $vf_email,
                        ];
                }
            }
            return $domains;
        }

        public function list_ssl(){
            Helper::Load(["User"]);

            $response = $this->api->orders();
            if(!$response && $this->api->error) $this->error = $this->api->error;

            $result     = [];

            if($response){
                foreach($response AS $res){
                    $cdate = isset($res["orders.creationdt"]) ? DateManager::timetostr("Y-m-d H:i",$res["orders.creationdt"]) : '';
                    $edate = isset($res["orders.endtime"]) ? DateManager::timetostr("Y-m-d H:i",$res["orders.endtime"]) : '';
                    $domain = isset($res["entity.description"]) ? $res["entity.description"] : '';
                    if($domain){
                        $order_id    = 0;
                        $user_data   = [];
                        $is_imported = Models::$init->db->select("id,owner_id AS user_id")->from("users_products");
                        $is_imported->where("type",'=',"special","&&");
                        $is_imported->where("module",'=',__CLASS__,"&&");
                        $is_imported->where("options",'LIKE','%"domain":"'.$domain.'"%');
                        $is_imported = $is_imported->build() ? $is_imported->getAssoc() : false;
                        if($is_imported){
                            $order_id   = $is_imported["id"];
                            $user_data  =  User::getData($is_imported["user_id"],"id,full_name,company_name","array");
                        }


                        $result[] = [
                            'api_orderid'       => $res["orders.orderid"],
                            'domain'            => $domain,
                            'creation_date'     => $cdate,
                            'end_date'          => $edate,
                            'order_id'          => $order_id,
                            'user_data'        => $user_data,
                        ];
                    }
                }
            }

            return $result;
        }

        public function import($data=[]){
            $config     = $this->config;

            $imports = [];

            Helper::Load(["Orders","Products","Money","Events"]);

            if(function_exists("ini_set")) ini_set("max_execution_time",3600);

            foreach($data AS $domain=>$datum){
                $user_id        = isset($datum["user_id"]) ? (int) $datum["user_id"] : 0;
                $api_orderid    = isset($datum["api_orderid"]) ? (int) $datum["api_orderid"] : 0;

                if(!$user_id) continue;
                $info           = $this->get_details($api_orderid);
                if(!$info) continue;

                $user_data          = User::getData($user_id,"id,lang","array");
                $ulang              = $user_data["lang"];
                $locallang          = Config::get("general/local");
                $plan_id            = $info["planid"];

                $product          = Models::$init->db->select("id,type_id,module_data")->from("products");
                $product->where("type","=","special","&&");
                $product->where("module","=",__CLASS__,"&&");
                $product->where("module_data","LIKE",'%"plan-id":"'.$plan_id.'"%');
                $product          = $product->build() ? $product->getAssoc() : false;

                if(!$product) continue;
                $productID  = $product["id"];

                $productPrice       = Products::get_price("periodicals","products",$productID);
                if(!$productPrice) continue;

                $productPrice_amt   = $productPrice["amount"];
                $productPrice_cid   = $productPrice["cid"];

                $start_date         = DateManager::timetostr("Y-m-d H:i:s",$info["creationtime"]);
                $end_date           = DateManager::timetostr("Y-m-d H:i:s",$info["endtime"]);

                $group_u              = Products::getCategoryName($product["type_id"],$ulang);
                $group_l              = Products::getCategoryName($product["type_id"],$locallang);
                $productName          = Products::get_info_by_fields("special",$productID,["t2.title"],$ulang);
                $productName          = $productName["title"];

                $options            = [
                    "established"         => true,
                    "group_name"          => $group_u,
                    "local_group_name"    => $group_l,
                    "category_id"         => 0,
                    "domain"              => $domain,
                    "csr-code"            => '',
                    "verification-email"  => '',
                    "config"              => [
                        "order_id"        => $api_orderid,
                    ],
                    "creation_info"       => Utility::jdecode($product["module_data"],true),
                ];


                $u_lang = Modules::Lang("Product",__CLASS__,$ulang);

                $certificate   = $this->get_cert_details($api_orderid);

                if($certificate){
                    $folder         = ROOT_DIR.RESOURCE_DIR."uploads".DS."orders".DS;
                    $name           = Utility::generate_hash(20,false,'ld').".txt";
                    $file_name      = $folder.$name;

                    $save           = FileManager::file_write($file_name,$certificate);
                    if($save){
                        $options["delivery_file"] = $name;
                        $options["delivery_file_button_title"] = $u_lang["delivery_file_button_name"];
                        $options["delivery_title_name"] = $u_lang["delivery_title"];
                        $options["delivery_title_description"] = '';
                    }else{
                        $options["delivery_title_name"] = $u_lang["delivery_title"];
                        $options["delivery_title_description"] = $u_lang["delivery_description"];
                    }
                }


                $order_data             = [
                    "owner_id"          => (int) $user_id,
                    "type"              => "special",
                    "type_id"           => $product["type_id"],
                    "product_id"        => (int) $productID,
                    "name"              => $productName,
                    "period"            => "year",
                    "period_time"       => 1,
                    "amount"            => (float) $productPrice_amt,
                    "total_amount"      => (float) $productPrice_amt,
                    "amount_cid"        => (int) $productPrice_cid,
                    "status"            => "active",
                    "cdate"             => $start_date,
                    "duedate"           => $end_date,
                    "renewaldate"       => $start_date,
                    "module"            => __CLASS__,
                    "options"           => Utility::jencode($options),
                    "unread"            => 1,
                ];

                $insert                 = Orders::insert($order_data);
                if(!$insert) continue;

                if(!$certificate && $this->error === "Certificate not enrolled")
                    Events::add_scheduled_operation([
                        'owner'             => "order",
                        'owner_id'          => $insert,
                        'name'              => "run-action-for-order-module",
                        'period'            => 'minute',
                        'time'              => 5,
                        'module'            => __CLASS__,
                        'command'           => "checking-ssl-enroll",
                    ]);


                $imports[] = $order_data["name"]." (#".$insert.")";
            }

            if($imports){
                $adata      = UserManager::LoginData("admin");
                User::addAction($adata["id"],"alteration","imported-ssl-orders",[
                    'module'   => $config["meta"]["name"],
                    'imported'  => implode(", ",$imports),
                ]);
            }

            return $imports;
        }

        public function add_requirements($product=[],$step_data=[]){
            if(!$product) return false;
            if(!($product["type"] == "special" && $product["module"] == __CLASS__)) return false;
            $domain = "******.***";

            $additional_sans_count  = 0;
            $included_sans_count    = 0;
            if(isset($product["module_data"]["included_sans"]) && $product["module_data"]["included_sans"])
                $included_sans_count = $product["module_data"]["included_sans"];

            if(isset($product["module_data"]["sans_status"]) && $product["module_data"]["sans_status"]){
                if(isset($step_data["addons"]) && $step_data["addons"]){
                    foreach($step_data["addons"] AS $k=>$v){
                        $getAddon = Products::addon($k);
                        if($getAddon){
                            $addon_opts = isset($getAddon["options"]) ? $getAddon["options"] : [];
                            foreach($addon_opts AS $row){
                                if($row["id"] == $v){
                                    if(isset($step_data["addons_values"][$k])){
                                        if(isset($row["module"][__CLASS__]["configurable"]["sans_count"])){
                                            $additional_sans_count += $step_data["addons_values"][$k];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $sans_count = ($included_sans_count + $additional_sans_count);


            $requirements           = [];

            $define_end_of_element_2   = "
                <script type='text/javascript'>
                    $(document).ready(function(){
                        $('#requirement-dcv-method').change(function(){
                            var val = $(this).val();
                            if(val !== 'email')
                                $('#requirement-verification-email-wrap').hide(1);
                            else
                                $('#requirement-verification-email-wrap').show(1);
                        });
                    });
                </script>
            ";
            $define_end_of_element_3 = "
                <script type='text/javascript'>
                $(document).ready(function(){
                    $('#requirement-additional-domains').css('display','none');
                    var td1 = $('#requirement-additional-domains-wrap td:nth-child(1)').html();
                    $('#requirement-additional-domains-wrap td:nth-child(1)').remove();
                    $('#requirement-additional-domains-wrap td:nth-child(1)')
                    .attr('colspan',2).prepend(td1);
                    
                    $('#requirement-additional-domains-wrap').change(function(){
                    var data = [];
                    for(var i=1; i<=".$sans_count.";i++){
                        var san_domain = $('input[name=\'san_domains['+i+']\']',this).val();
                        var dcv_method = $('select[name=\'san_dcv_methods['+i+']\']',this).val();
                        var vf_email   = $('select[name=\'san_verification_emails['+i+']\']',this).val();
                        if(san_domain.length >= 5) data.push(san_domain+':'+dcv_method+':'+vf_email);
                    }
                    if(data.length>0) data = data.join('\\n');
                    else data = '';
                    $('#requirement-additional-domains').val(data);
                    });
                });
                </script>
                <div id='define_san_domains'>
                <div class='formcon'>
                <div class='yuzde50'>".$this->lang["domain"]."</div>
                <div class='yuzde50'>".$this->lang["dcv-method"]."</div>
                </div>
            ";

            foreach(range(1,$sans_count) AS $i){
                $define_end_of_element_3 .= "
                    <div class='formcon'>
                        <div class='yuzde50' style='vertical-align:middle;'>
                            <input type='text' name='san_domains[".$i."]' placeholder='example.com'>
                        </div>
                        <div class='yuzde50'>
                            <select name='san_dcv_methods[".$i."]' class='yuzde20' onchange='if($(this).val() === \"email\"){ $(this).next(\"select\").css(\"visibility\",\"visible\"); }else{ $(this).next(\"select\").css(\"visibility\",\"hidden\");}'>
                                <option value='email'>EMAIL</option>
                                <option value='http'>HTTP</option>
                                <option value='https'>HTTPS</option>
                                <option value='dns'>DNS</option>
                            </select>
                            <select name='san_verification_emails[".$i."]' class='yuzde80'>
                                <option value='webmaster'>webmaster@******.***</option>
                                <option value='hostmaster'>hostmaster@******.***</option>
                                <option value='admin'>admin@******.***</option>
                                <option value='administrator'>administrator@******.***</option>
                                <option value='postmaster'>postmaster@******.***</option>
                            </select>
                        </div>
                    </div>
                ";
            }

            $define_end_of_element_3 .= "
                </div>
            ";

            $requirements[] = [
                'id'                => "domain",
                'name'              => $this->lang["domain"],
                'description'       => $this->lang["domain-desc"],
                'type'              => "input",
                'properties'        => [
                    "compulsory" => true,
                    "placeholder" => "example.com",
                    "define_attribute_to_basket_item_options" => "domain",
                ],
                'options'           => [],
            ];

            $requirements[] = [
                'id'                => "csr-code",
                'name'              => $this->lang["csr-code"],
                'description'       => $this->lang["csr-code-desc"],
                'type'              => "textarea",
                'properties'        => [
                    "compulsory" => true,
                    "define_attribute_to_basket_item_options" => "csr-code",
                    "placeholder"   => '-----BEGIN CERTIFICATE REQUEST-----'.PHP_EOL.PHP_EOL.'-----END CERTIFICATE REQUEST-----',
                ],
                'options'           => [],
            ];

            $requirements[] = [
                'id'                => "dcv-method",
                'name'              => $this->lang["dcv-method"],
                'description'       => $this->lang["dcv-method-desc"],
                'type'              => "select",
                'properties'        => [
                    "compulsory" => true,
                    "define_attribute_to_basket_item_options" => "dcv-method",
                    "define_end_of_element" => $define_end_of_element_2,
                ],
                'options'           => [
                    [
                        'id'        => "email",
                        'name'      => "EMAIL",
                    ],
                    [
                        'id'        => "http",
                        'name'      => "HTTP",
                    ],
                    [
                        'id'        => "https",
                        'name'      => "HTTPS",
                    ],
                    [
                        'id'        => "dns",
                        'name'      => "DNS",
                    ],
                ],
            ];

            $requirements[] = [
                'id'                => "verification-email",
                'name'              => $this->lang["verification-email"],
                'description'       => $this->lang["verification-email-desc"],
                'type'              => "radio",
                'properties'        => [
                    "define_attribute_to_basket_item_options" => "verification-email",
                    "wrap_visibility" => true,
                ],
                'options'           => [
                    [
                        'id'     => "webmaster",
                        'name'   => "webmaster@".$domain,
                    ],
                    [
                        'id'     => "hostmaster",
                        'name'   => "hostmaster@".$domain,
                    ],
                    [
                        'id'     => "admin",
                        'name'   => "admin@".$domain,
                    ],
                    [
                        'id'     => "administrator",
                        'name'   => "administrator@".$domain,
                    ],
                    [
                        'id'     => "postmaster",
                        'name'   => "postmaster@".$domain,
                    ],
                ],
            ];

            if($sans_count)
                $requirements[] = [
                    'id'                => "additional-domains",
                    'name'              => $this->lang["additional-domains"]." <strong>(".$sans_count." SAN)</strong>",
                    'description'       => $this->lang["additional-domains-desc"],
                    'type'              => "textarea",
                    'properties'        => [
                        "define_attribute_to_basket_item_options" => "additional-domains",
                        "placeholder"   => "example1.com".EOL."example2.com",
                        "define_end_of_element" => $define_end_of_element_3,
                    ],
                    'options'           => [],
                ];

            return $requirements;
        }

        public function filter_requirement($data=[]){
            $product        = $data["product"];
            $step_data      = $data["step_data"];
            $requirement    = $data["requirement"];
            $value          = $data["value"];

            if(!$product) return false;
            if(!($product["type"] == "special" && $product["module"] == __CLASS__)) return false;

            if($requirement["id"] == "domain"){
                $value     = Filter::domain($value);

                $value     = str_replace("www.","",$value);
                $value     = trim($value);

                if(!filter_var($value,FILTER_VALIDATE_IP)){
                    $sld        = NULL;
                    $tld        = NULL;
                    $parse      = Utility::domain_parser("http://".$value);
                    if($parse["host"] != '' && strlen($parse["host"]) >= 2){
                        $sld    = $parse["host"];
                        $tld    = $parse["tld"];
                    }
                    if(!$sld || !$tld) $value = '';
                }
            }

            $this->_temp["requirements"][$requirement["id"]] = $value;

            return ["value" => $value];
        }

        public function checking_requirement($data=[]){
            $product        = $data["product"];
            $step_data      = $data["step_data"];
            $requirement    = $data["requirement"];
            $value          = $data["value"];

            if(!$product) return false;
            if(!($product["type"] == "special" && $product["module"] == __CLASS__)) return false;
            $domain = false;
            if(isset($this->_temp["requirements"]["domain"])) $domain = $this->_temp["requirements"]["domain"];


            if($requirement["id"] == "additional-domains"){
                $domains        = [];
                $c_value        = explode(EOL,$value);

                $additional_sans_count  = 0;
                $included_sans_count    = 0;
                if(isset($product["module_data"]["included_sans"]) && $product["module_data"]["included_sans"])
                    $included_sans_count = $product["module_data"]["included_sans"];

                if(isset($product["module_data"]["sans_status"]) && $product["module_data"]["sans_status"]){
                    if(isset($step_data["addons"]) && $step_data["addons"]){
                        foreach($step_data["addons"] AS $k=>$v){
                            $getAddon = Products::addon($k);
                            if($getAddon){
                                $addon_opts = isset($getAddon["options"]) ? $getAddon["options"] : [];
                                foreach($addon_opts AS $row){
                                    if($row["id"] == $v){
                                        if(isset($step_data["addons_values"][$k])){
                                            if(isset($row["module"][__CLASS__]["configurable"]["sans_count"])){
                                                $additional_sans_count += $step_data["addons_values"][$k];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                $sans_count = ($included_sans_count + $additional_sans_count);

                foreach($c_value AS $c_val) $domains[] = Filter::domain($c_val);

                if(sizeof($domains) > $sans_count)
                    return [
                        'status' => "error",
                        'message' => $this->lang["error10"],
                    ];
            }

            if($requirement["id"] == "csr-code"){
                $csr_data = openssl_csr_get_subject($value);
                if(!$csr_data || !$csr_domain = Utility::strtolower($csr_data["CN"]))
                    return [
                        'status' => "error",
                        'message' => $this->lang["error3"],
                    ];

                $csr_domain     = str_replace("www.","",$csr_domain);

                if($domain !== $csr_domain)
                    return [
                        'status' => "error",
                        'message' => $this->lang["error4"],
                    ];
            }

            if($requirement["id"] == "verification-email"){
                if(isset($this->_temp["fields"]["dcv-method"])){
                    if($this->_temp["fields"]["dcv-method"] == "email" && Validation::isEmpty($value)){
                        return [
                            'status' => "error",
                            'message' => __("website/osteps/field-required",['{name}' => $requirement["name"]]),
                        ];
                    }
                }
            }

            $this->_temp["fields"][$requirement["id"]] = $value;

            return false;
        }

    }

    Hook::add("addRequirementToOrderSteps",1,[
        'class'     => "ExampleSSL",
        'method'    => "add_requirements",
    ]);

    Hook::add("filterRequirementToOrderSteps",1,[
        'class'     => "ExampleSSL",
        'method'    => "filter_requirement",
    ]);

    Hook::add("checkingRequirementToOrderSteps",1,[
        'class'     => "ExampleSSL",
        'method'    => "checking_requirement",
    ]);