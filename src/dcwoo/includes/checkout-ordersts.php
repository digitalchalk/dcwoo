<?php
// Change Order Status By Product Type
add_action('woocommerce_thankyou', 'enroll_student', 10, 1);
function enroll_student( $order_id ) {
    
    $order = wc_get_order( $order_id );
    $items = $order->get_items();

    foreach ( $items as $item ) {
        
        $product_id = $item->get_product_id();
        $product = wc_get_product($product_id);
        $type =  $product->get_type();
        if($product->is_virtual())
        {
            $order->update_status( 'wc-completed' );
        }
        elseif($type == "simple")
        {
            $order->update_status( 'wc-processing' );
        }
        
       
    }


    
}


add_action('profile_update', 'my_dcprofile_update', 10, 2);
function my_dcprofile_update($user_id)
{
    $token = get_option('dcwoo_token');
    $hostname = get_option('dcwoo_hostname');
    

    $url = 'https://' . $hostname ;

    $user_info = get_userdata($user_id);

    $user_email = $user_info->user_email;

    //  Get Token
   
  
        //  Check User Available With Email id
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url."/dc/api/v5/users?email=" . $user_email,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $token,
                "Content-Type:  application/json",
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $result = json_decode($response);

        // Check Email Id Match With Dcsso Account
        if (!empty($result->results)) {

            save_dcuserfiled($user_id, $result->results[0]->id);
        }
    

}

function save_dcuserfiled($user_id, $sscid)
{

            $token = get_option('dcwoo_token');
            $hostname = get_option('dcwoo_hostname');
            

			$url = 'https://' . $hostname ;
    //  Get Token
    

        $curl2 = curl_init();

        curl_setopt_array($curl2, array(
            CURLOPT_URL => $url."/dc/api/v5/userfields",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $token,
                "Content-Type:  application/json",
            ),
        ));

        $response = curl_exec($curl2);

        curl_close($curl2);
        $res = json_decode($response, true);
        foreach ($res['results'] as $ufild) {
            if ($ufild['name'] == "Phone Number") {
                $phone = $ufild['id'];
            }

            if ($ufild['name'] == "Street 1") {
                $street1 = $ufild['id'];
            }

            if ($ufild['name'] == "Street 2") {
                $street2 = $ufild['id'];
            }

            if ($ufild['name'] == "State/Province") {
                $state = $ufild['id'];
            }

            if ($ufild['name'] == "Postal Code") {
                $pcode = $ufild['id'];
            }

            if ($ufild['name'] == "City") {
                $city = $ufild['id'];
            }

        }

        $cssoid = $sscid;
        $user_info = array($phone => get_user_meta($user_id, 'billing_phone', true), $street1 => get_user_meta($user_id, 'billing_address_1', true), $street2 => get_user_meta($user_id, 'billing_address_2', true), $state => get_user_meta($user_id, 'billing_state', true), $pcode => get_user_meta($user_id, 'billing_postcode', true), $city => get_user_meta($user_id, 'billing_city', true));
        // update_user_profile($cssoid, $user_info);
        $curl3 = curl_init();

        curl_setopt_array($curl3, array(
            CURLOPT_URL =>  $url."/dc/api/v5/users/" . $cssoid . "/userfieldvalues",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_POSTFIELDS => json_encode($user_info),
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $token,
                "Content-Type:  application/json",
                "Accept:  application/json",
            ),
        ));

        $response = curl_exec($curl3);

        curl_close($curl3);
        $res = json_decode($response);

    

}