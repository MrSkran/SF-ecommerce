<?php

namespace App\Http\Controllers\Front;

use App\Classes\GeniusMailer;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Generalsetting;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderTrack;
use App\Models\Product;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\VendorOrder;
use Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use App\Classes\WockAPI;

class PaylinkPaymentController extends Controller
{

    public function store(Request $request)
    {


        if ($request->pass_check) {
            $users = User::where('email', '=', $request->personal_email)->get();
            if (count($users) == 0) {
                if ($request->personal_pass == $request->personal_confirm) {
                    $user = new User;
                    $user->name = $request->personal_name;
                    $user->email = $request->personal_email;
                    $user->password = bcrypt($request->personal_pass);
                    $token = md5(time() . $request->personal_name . $request->personal_email);
                    $user->verification_link = $token;
                    $user->affilate_code = md5($request->name . $request->email);
                    $user->save();
                    Auth::guard('web')->login($user);
                } else {
                    return redirect()->back()->with('unsuccess', "Confirm Password Doesn't Match.");
                }
            } else {
                return redirect()->back()->with('unsuccess', "This Email Already Exist.");
            }
        }

        if (!Session::has('cart')) {
            return redirect()->route('front.cart')->with('success', "You don't have any product to checkout.");
        }
        $oldCart = Session::get('cart');
        $cart = new Cart($oldCart);
        if (Session::has('currency')) {
            $curr = Currency::find(Session::get('currency'));
        } else {
            $curr = Currency::where('is_default', '=', 1)->first();
        }
        foreach ($cart->items as $key => $prod) {
            if (!empty($prod['item']['license']) && !empty($prod['item']['license_qty'])) {
                foreach ($prod['item']['license_qty'] as $ttl => $dtl) {
                    if ($dtl != 0) {
                        $dtl--;
                        $produc = Product::findOrFail($prod['item']['id']);
                        $temp = $produc->license_qty;
                        $temp[$ttl] = $dtl;
                        $final = implode(',', $temp);
                        $produc->license_qty = $final;
                        $produc->update();
                        $temp =  $produc->license;
                        $license = $temp[$ttl];
                        $oldCart = Session::has('cart') ? Session::get('cart') : null;
                        $cart = new Cart($oldCart);
                        $cart->updateLicense($prod['item']['id'], $license);
                        Session::put('cart', $cart);
                        break;
                    }
                }
            }
        }
        $settings = Generalsetting::findOrFail(1);
        $order = new Order;
        //$paypal_email = $settings->paypal_business;

        $success_url = action('Front\PaylinkPaymentController@paysuccess');
        $item_name = $settings->title . " Order";
        $item_amount = $request->total / $curr->value;
        $item_number = str_random(4) . time();

        //$order['pay_amount'] = round($item_amount / $curr->value, 2)  + $request->shipping_cost + $request->packing_cost;






        $order['user_id'] = $request->user_id;
        $order['cart'] = utf8_encode(bzcompress(serialize($cart), 9));
        $order['totalQty'] = $request->totalQty;
        // $order['pay_amount'] = round($item_amount / $curr->value, 2)  + $request->shipping_cost + $request->packing_cost;

        if ($curr->sign == "S.R") {
            $order['pay_amount'] = $request->total;
        } elseif ($curr->sign  == "$") {
            $order['pay_amount'] = $request->total * 3.75;
        } elseif ($curr->sign  == "€") {
            $order['pay_amount'] = $request->total * 4.52834;
        } else {
            // return redirect()->back()->with('unsuccess', "currency value Doesn't Match.");
        }

        $order['method'] = $request->method;
        $order['customer_email'] = $request->email;
        $order['customer_name'] = $request->name;
        $order['customer_phone'] = $request->phone;
        $order['order_number'] = $item_number;
        $order['shipping'] = $request->shipping;
        $order['pickup_location'] = $request->pickup_location;
        $order['customer_address'] = $request->address;
        $order['customer_country'] = $request->customer_country;
        $order['customer_city'] = $request->city;
        $order['customer_zip'] = $request->zip;
        $order['shipping_email'] = $request->shipping_email;
        $order['shipping_name'] = $request->shipping_name;
        $order['shipping_phone'] = $request->shipping_phone;
        $order['shipping_address'] = $request->shipping_address;
        $order['shipping_country'] = $request->shipping_country;
        $order['shipping_city'] = $request->shipping_city;
        $order['shipping_zip'] = $request->shipping_zip;
        $order['order_note'] = $request->order_notes;
        $order['coupon_code'] = $request->coupon_code;
        $order['coupon_discount'] = $request->coupon_discount;
        $order['payment_status'] = "Pending";
        $order['currency_sign'] = $curr->sign;
        $order['currency_value'] = $curr->value;
        $order['shipping_cost'] = $request->shipping_cost;
        $order['packing_cost'] = $request->packing_cost;
        $order['tax'] = $request->tax;
        $order['dp'] = $request->dp;

        $order['adapter_name'] = $request->adapter_name;
        $order['transfer_amount'] = $request->transfer_amount;
        $order['transfer_date'] = $request->transfer_date;
        // $order['transaction_id'] = $request->transaction_id;
        if (Session::has('affilate')) {
            $val = $request->total / 100;
            $sub = $val * $settings->affilate_charge;
            $user = User::findOrFail(Session::get('affilate'));
            $user->affilate_income += $sub;
            $user->update();
            $order['affilate_user'] = $user->name;
            $order['affilate_charge'] = $sub;
        }
        //$order->save();



        //$track->save();


        $ite = 0;
        foreach ($cart->items as $key => $prod) {
            $te[$ite]['description'] = "";
            $te[$ite]['imageSrc'] = "http://localhost/SF-ecommerce/assets/images/products/" . $prod['item']['photo'];
            $te[$ite]['price'] = $prod['item']['price'];
            $te[$ite]['qty'] = $prod['qty'];
            $te[$ite]['title'] = $prod['item']['name'];
            $ite++;
        }
        /*
                [
                    {
                        "description": "Brown Hand bag leather for ladies",
                        "imageSrc": "http://merchantwebsite.com/img/img1.jpg",
                        "price": 150,
                        "qty": 1,
                        "title": "Hand bag"
                    }
                ]
                */

        /*
                        function GetToken()
                        {

                            $settingV = array(
                                "apiId" => "APP_ID_1123453311", // Data model to send to login using Merchant API account
                                "persistToken" => false, //This is boolean value. if set to true, then the returned token valid for 30 hours. Otherwise, the returned token will be valid for 30 minutes.
                                "secretKey" => "0662abb5-13c7-38ab-cd12-236e58f43766"
                                //This secret key and must be saved in a secure place and must not be exposed outside the server side of the merchant system.
                                // Secret key is given by Paylink. If you need the SECRET KEY, send request for Merchant API account to email info@paylink.sa
                            );
                            //global $setting;
                            $curl = curl_init();
                            curl_setopt_array($curl, array(
                                CURLOPT_URL => 'restpilot.paylink.sa/api/auth',
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_ENCODING => '',
                                CURLOPT_MAXREDIRS => 10,
                                CURLOPT_TIMEOUT => 0,
                                CURLOPT_FOLLOWLOCATION => true,
                                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                                CURLOPT_CUSTOMREQUEST => 'POST',
                                CURLOPT_POSTFIELDS => json_encode($settingV),
                                CURLOPT_HTTPHEADER => array(
                                    'Content-Type: application/json'
                                ),
                            ));

                            $response = curl_exec($curl);

                            curl_close($curl);
                            $response = json_decode($response, true);
                            return $response['id_token'];
                        }
                */
        function Addinv($token, $order, $te, $success_url)
        {
            //global $te;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'restpilot.paylink.sa/api/addInvoice',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => '{
                "amount": ' . $order['pay_amount']  . ',
                "callBackUrl": "' . $success_url . '",
                "clientEmail": "' . $order['customer_email'] . '",
                "clientMobile": "' . $order['customer_phone'] . '",
                "clientName": "' . $order['customer_name'] . '",
                "note": "' . $order['order_note'] . '",
                "orderNumber": "' . $order['order_number'] . '",
                "products": ' . json_encode($te, true) . '
            }',
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Bearer ' . $token . '',
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);
            $response = json_decode($response, true);
            return $response;
        }
        $GetToken = $this->GetToken();

        $backcall = Addinv($GetToken, $order, $te, $success_url);

        if ($backcall['success'] == true) {
            if ($backcall['amount'] == $order['pay_amount']) {
                if ($backcall['orderStatus'] == "CREATED") {
                    if ($backcall['paymentErrors'] == null) {


                        $order['transaction_id'] = $backcall['transactionNo'];

                        $order->save();


                        $track = new OrderTrack;
                        $track->title = 'Pending';
                        $track->text = 'You have successfully placed your order.';
                        $track->order_id = $order->id;
                        $track->save();

                        if ($request->coupon_id != "") {
                            $coupon = Coupon::findOrFail($request->coupon_id);
                            $coupon->used++;
                            if ($coupon->times != null) {
                                $i = (int)$coupon->times;
                                $i--;
                                $coupon->times = (string)$i;
                            }
                            $coupon->update();
                        }





                        foreach ($cart->items as $prod) {
                            $x = (string)$prod['stock'];
                            if ($x != null) {
                                $product = Product::findOrFail($prod['item']['id']);
                                $product->stock =  $prod['stock'];
                                $product->update();
                            }
                        }

                        $notf = null;

                        foreach ($cart->items as $prod) {
                            if ($prod['item']['user_id'] != 0) {
                                $vorder =  new VendorOrder;
                                $vorder->order_id = $order->id;
                                $vorder->user_id = $prod['item']['user_id'];
                                $notf[] = $prod['item']['user_id'];
                                $vorder->qty = $prod['qty'];
                                $vorder->price = $prod['price'];
                                $vorder->order_number = $order->order_number;
                                $vorder->save();
                            }
                        }


                        if (!empty($notf)) {
                            $users = array_unique($notf);
                            foreach ($users as $user) {
                                $notification = new UserNotification;
                                $notification->user_id = $user;
                                $notification->order_number = $order->order_number;
                                $notification->save();
                            }
                        }





                        Session::put('temporder', $order);
                        Session::put('tempcart', $cart);



                        Session::forget('cart');
                        Session::forget('already');
                        Session::forget('coupon');
                        Session::forget('coupon_total');
                        Session::forget('coupon_total1');
                        Session::forget('coupon_percentage');





                        return redirect($backcall['url']);
                    }
                }
            }
        }
    }

    public function paysuccess(Request $request)
    {
        $GetToken = $this->GetToken();

        function getInvoice($e, $d)
        {

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'restpilot.paylink.sa/api/getInvoice/' . $e,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Bearer ' . $d . '',
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);
            curl_close($curl);
            $response = json_decode($response, true);
            return $response;
        }

        function setPiad($transactionNo, $orderNumber, $GetToken)
        {
            $product = Order::where('order_number', '=', $orderNumber)->where('transaction_id', '=', $transactionNo)->first();
            if (Session::has('tempcart')) {
                $oldCart = Session::get('tempcart');
                $tempcart = new Cart($oldCart);
                //$order = Session::get('temporder');
                $order = $product;
            } else {
                $tempcart = '';
                return redirect()->back();
            }
            $getInvoice = getInvoice($transactionNo, $GetToken);

            if ($product['payment_status'] == "Completed") {
                return view('front.success', compact('tempcart', 'order'));
            }
            if (Session::has('currency')) {
                $curr = Currency::find(Session::get('currency'));
            } else {
                $curr = Currency::where('is_default', '=', 1)->first();
            }

            if ($getInvoice['paymentErrors'] == null) {
                if ($getInvoice['orderStatus'] == 'Paid') {
                    if ($getInvoice['amount'] == $product->pay_amount) {
                        $product->method = "Paylink";
                        $product->transfer_amount = $getInvoice['amount'];
                        $product->adapter_name = $getInvoice['gatewayOrderRequest']['clientName'];
                        $product->payment_status =  "Completed";
                        $product->transfer_date = date("F j, Y, g:i a");
                        $product->update();

                        // SET WOCK Licence
                        $wockAPI = new WockAPI();
                        $return = $wockAPI->getProductsFromCart($product->id);
                        // END WOCK

                        $prod44 = "";
                        $prod45 = '<table class="all t"><thead><tr class="all hd"><th class="all hd">المجموع النهائي:</th><th class="all hd">'. $curr->sign . (round($order->pay_amount*$curr->value,2)).'</th></tr></thead></table></h5>';
                        foreach($tempcart->items as $prod12)
                        {
                            $prod44 .= ' <td class="all hd">'.$prod12["item"]["name"]. '</td><td class="all hd">'.$prod12['qty'].'</td><td class="all hd">'. $curr->sign . number_format($prod12["item"]["price"] * $order->currency_value,2,'.','') .'</td> <td class="all hd">'. $curr->sign . number_format($prod12["price"] * $order->currency_value,2,'.','') .'</td></tr>'.'</br>';
                        }
                        
                        $data = [
                            'to' => $order->customer_email,
                            'subject' => " $order->order_number الطلب - Soft Fire",
                            'body' => '<head>
                            <style>
                            .all{border:1px solid #ddd;text-align:right;}
                            .t{border-collapse:collapse;width:100%;}
                            .hd{padding:15px;}
                            </style>
                            </head><table cellspacing="0" cellpadding="0" width="600" align="center" border="0" style="direction:rtl;font-family:Roboto,RobotoDraft,Helvetica,Arial,sans-serif;font-size:14px;background-color:rgb(254,255,255);width:600px;margin:0 auto;"><tbody><tr><th style="text-align:left;border-width:medium;border-style:none;border-color:initial;width:600px;font-weight:normal;padding:0;"><table cellspacing="0" cellpadding="0" align="left" style="width:700.259px;"><tbody><tr height="102" style="height:102px;"><th style="text-align:left;border-width:medium;border-style:none;border-color:initial;width:570px;vertical-align:top;font-weight:normal;padding:20px 15px;background-color:transparent;"><table cellspacing="0" cellpadding="0" align="center" border="0" style="direction:rtl;margin:0 auto;"><tbody><tr><td align="center" style="padding:2px;"><table cellspacing="0" cellpadding="0" border="0"><tbody><tr><td style="border-width:medium;border-style:none;border-color:initial;background-color:transparent;"><img alt="" src="https://soft-fire.com/assets/images/1584584979rtllogo(EN).png" width="246" hspace="0" vspace="0" style="border-width:medium;border-color:initial;border-image:initial;outline:none;display:block;"></td></tr></tbody></table></td></tr></tbody></table></th></tr></tbody></table></th></tr><tr><th style="text-align:left;border-width:5px medium medium;border-style:solid none none;border-top-color:rgb(159,64,255);border-right-color:initial;width:600px;border-bottom-color:initial;font-weight:normal;padding:0;border-left-color:initial;"><table cellspacing="0" cellpadding="0" align="left" style="width:599.259px;"><tbody><tr height="206" style="height:206px;"><th style="text-align:left;border-width:medium;border-style:none;border-color:initial;width:570px;vertical-align:top;padding:10px 15px 20px;background-color:transparent;"><p style="margin-right:0;margin-bottom:10px;margin-left:0;color:rgb(68,68,68);line-height:23px;font-family:Helvetica,Arial,sans-serif;text-align:right;font-weight:normal;padding:0;">مرحباً '.$order->customer_name.'.</p><p style="color:rgb(68,68,68);font-family:Helvetica,Arial,sans-serif;font-weight:normal;text-align:right;background-color:rgb(255,255,255);margin-right:0;margin-bottom:10px;margin-left:0;padding:0;line-height:23px;">شكرا لتعاملكم مع سوفت فاير لقد تم استلام طلبك وسيتم انهاء الطلب بعد التأكد من اتمام عملية الدفع.</p><div style="width:268.862px;padding-right:15px;padding-left:15px;max-width:50%;color:rgb(51,51,51);font-family:&quot;Open Sans&quot;,sans-serif;font-size:16px;text-align:right;background-color:rgb(255,255,255);"><h5 style="font-size:18px;color:rgb(159,64,255);">تفاصيل الطلب</h5><address style="font-weight:400;font-size:14px;">رقم الطلب: '.$order->order_number.'<br>تاريخ الطلب: '.$order->created_at.'<br>البريد الإلكتروني: '.$order->customer_email.'<br><address>رقم الجوال او الهاتف: '.$order->customer_phone.'<br>&nbsp;طريقة الدفع : '.$order->method.'</address><address></address></address><address style="font-weight:400;font-size:14px;"><span style="color:rgb(159,64,255);font-size:18px">معلومات الدفع</span></address></div><div style="width:268.862px;padding-right:15px;padding-left:15px;max-width:50%;color:rgb(51,51,51);font-family:&quot;Open Sans&quot;,sans-serif;font-weight:400;text-align:right;background-color:rgb(255,255,255);"><span style="text-align:left;"><span style="font-weight:bolder;">Paylink</span></span></div><div style="color:rgb(70,85,65);font-size:16px;"><span style="text-align:left;"><span style="font-weight:bolder;"><br></span></span></div></div><p style="color:rgb(68,68,68);font-family:Helvetica,Arial,sans-serif;font-weight:normal;text-align:right;background-color:rgb(255,255,255);margin-right:0;margin-bottom:10px;margin-left:0;padding:0;line-height:23px;"><span style="color:rgb(159,64,255);font-family:&quot;Open Sans&quot;,sans-serif;font-size:18px"><br></span></p><p style="color:rgb(68,68,68);font-family:Helvetica,Arial,sans-serif;font-weight:normal;text-align:right;background-color:rgb(255,255,255);margin-right:0;margin-bottom:10px;margin-left:0;padding:0;line-height:23px;"><span style="color:rgb(159,64,255);font-family:&quot;Open Sans&quot;,sans-serif;font-size:18px">المنتجات</span><br></p><h5 style="font-size:18px;color:rgb(33,37,41);text-align:left;background-color:rgb(255,255,255);"><table class="all t"><thead><tr class="all hd"><th class="all hd">الاسم</th><th class="all hd">العدد</th><th class="all hd">السعر</th><th class="all hd">المجموع</th></tr></thead><tbody><tr class="all hd">                
                            '.$prod44. $prod45.'
                            <div style="color: rgb(51, 51, 51); font-size: 16px;"><br></div></h5><p style="color: rgb(68, 68, 68); font-family: Helvetica, Arial, sans-serif; font-weight: normal; text-align: right; background-color: rgb(255, 255, 255); margin-right: 0px; margin-bottom: 10px; margin-left: 0px; padding: 0px; line-height: 23px;"><br></p><table style="text-align: center; color: rgb(68, 68, 68); font-family: Helvetica, Arial, sans-serif; font-weight: 400; border-spacing: 0px; padding: 0px; vertical-align: middle; border-radius: 8px 8px 6px 6px; background-color: rgb(255, 255, 255); width: 670px; margin: 0px auto;"><tbody><tr style="padding: 0px; vertical-align: middle;"><td style="word-break: break-word; padding: 0px; vertical-align: middle; line-height: 23px; border-collapse: collapse;"><span style="display: block; font-size: 12px; padding: 10px 15px; text-align: right;"><div style="color: rgb(80, 0, 80);"><p style="margin-right: 0px; margin-bottom: 10px; margin-left: 0px; font-size: 14px; color: rgb(68, 68, 68); line-height: 23px; padding: 0px;"></p><div style="text-align: center;"><span style="font-weight: bolder;">&nbsp;مع أطيب التحيات&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;&nbsp;</span></div><div style="text-align: center;"><span style="font-weight: bolder;">&nbsp; &nbsp;فريق سوفت فاير.&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</span></div></div></span></td></tr></tbody></table><table cellspacing="0" cellpadding="0" style="font-weight: normal; width: 599.259px;"><tbody><tr height="114" style="height: 114px;"><th style="text-align: left; border-width: medium; border-style: none; border-color: initial; width: 570px; vertical-align: top; font-weight: normal; padding: 1px 15px;"><div style="padding: 10px; text-align: center;"><table cellspacing="0" cellpadding="0" border="0" style="display: inline-block;"><tbody><tr><td style="padding-right: 5px;"><a href="https://twitter.com/s0ftfire" target="_blank" data-saferedirecturl="https://www.google.com/url?q=https://twitter.com/s0ftfire&amp;source=gmail&amp;ust=1579009031373000&amp;usg=AFQjCNHgZHC805SqK1lhdY-E5toppdipPA"><img title="Twitter" alt="Twitter" src="https://ci4.googleusercontent.com/proxy/lfCcWsp6o9C2_6Ab5Xj-057OmadKeOSO_Bl836cfCQhxXO81rWW4AB09Ce3uboSiIYEaROoU_qCBV8ZyyvbrUjF4cx9Hh4ZIOzVcXmP8oTL4J3nuCc0jJfAvDuKi=s0-d-e1-ft#https://soft-fire.com/img/Image_3_48e377b3-7322-4b87-a4d0-1f7a801ac916.png" width="48" style="border-width: medium; border-color: initial; border-image: initial; outline: none; display: block;"></a></td><td><a href="https://www.youtube.com/channel/UC1LmIeyogjplV0BS7WwUvnA?view_as=subscriber" target="_blank" data-saferedirecturl="https://www.google.com/url?q=https://www.youtube.com/channel/UC1LmIeyogjplV0BS7WwUvnA?view_as%3Dsubscriber&amp;source=gmail&amp;ust=1579009031373000&amp;usg=AFQjCNGx_GO_vC8Njfw4P840UKb7_kIZvQ"><img title="Youtube" alt="Youtube" src="https://ci3.googleusercontent.com/proxy/wk6EsjlRhi3dHejisAu9QuTMXUotykaTVmttjBubb1YXQr70BqZLSpAIJQv1Kd2gtTtOpPpKr4fGjnCNCK1XtNuOMOlDWwDKe9n0LlORBjVe57Ts24Vam_09eOcG=s0-d-e1-ft#https://soft-fire.com/img/Image_4_2159d08f-1a89-493a-8d4e-01c53adc95e0.png" width="48" style="border-width: medium; border-color: initial; border-image: initial; outline: none; display: block;"></a></td></tr></tbody></table></div><p align="center" style="margin-right: 0px; margin-bottom: 1em; margin-left: 0px; font-size: 10px; color: rgb(124, 124, 124); line-height: 12px; font-family: arial, helvetica, sans-serif; padding: 0px; text-align: center; background-color: transparent;">&nbsp;</p><p align="center" style="direction: rtl; margin-right: 0px; margin-bottom: 1em; margin-left: 0px; font-size: 10px; color: rgb(124, 124, 124); line-height: 12px; font-family: arial, helvetica, sans-serif; padding: 0px; text-align: center; background-color: transparent;">. Soft-Fire . All rights reserved 2020 ©</p></th></tr></tbody></table></th></tr></tbody></table></th></tr></tbody></table>',
                        ];

                        $mailer = new GeniusMailer();
                        $mailer->sendCustomMail($data);        

                        
                        if ($product) {
                            

                            if (Session::has('tempcart')) {
                                $oldCart = Session::get('tempcart');
                                $tempcart = new Cart($oldCart);
                                //$order = Session::get('temporder');
                                $order = $product;
                            } else {
                                $tempcart = '';
                                return redirect()->back();
                            }

                            sleep(1);

                            return view('front.success', compact('tempcart', 'order'));
                        } else {
                            return redirect()->back()->with('unsuccess', 'Payment Cancelled.');
                        }
                        // return "No paymentErrors, orderStatus = Paid, and amount = pay_amount " . $product->pay_amount;
                    } else {
                        return 'unsuccess -> amount>' . $getInvoice['amount'];
                    }
                } else {
                    $order['temp01'] = "1";
                    return view('front.success', compact('tempcart', 'order'));
                    //return redirect()->back()->with('unsuccess', 'Payment Cancelled.');
                    //return 'unsuccess -> orderStatus>' . $getInvoice['orderStatus'];

                }
            } else {
                $order['paymentErrors'] = $getInvoice['paymentErrors'][0];
                return view('front.success', compact('tempcart', 'order'));
                //return 'unsuccess -> paymentErrors> ' . $getInvoice['paymentErrors'][0]['errorCode'];
                /* [{"errorCode":"3D","errorTitle":"3D Secure Error - Invalid card information",
                    "errorMessage":"3D Secure Error - Invalid card information",
                    "errorTime":"2021-01-17T15:31:11.000+0000"}
                    ]}
*/
            }
        }






        return setPiad($request->transactionNo, $request->orderNumber,  $GetToken);
        //return getInvoice($request->transactionNo, $GetToken);
    }

    public function GetToken()
    {

        $settingV = array(
            "apiId" => "APP_ID_1123453311", // Data model to send to login using Merchant API account
            "persistToken" => false, //This is boolean value. if set to true, then the returned token valid for 30 hours. Otherwise, the returned token will be valid for 30 minutes.
            "secretKey" => "0662abb5-13c7-38ab-cd12-236e58f43766"
            //This secret key and must be saved in a secure place and must not be exposed outside the server side of the merchant system.
            // Secret key is given by Paylink. If you need the SECRET KEY, send request for Merchant API account to email info@paylink.sa
        );
        //global $setting;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'restpilot.paylink.sa/api/auth',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($settingV),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $response = json_decode($response, true);
        return $response['id_token'];
    }
}
