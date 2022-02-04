
<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;

class PaymentController extends Controller
{
    protected $serviceUrl = 'https://e-commerce.kapitalbank.az:5443/Exec';
    protected $cert = "testmerchant.csr";
    protected $key = "merchant_name.key";
    protected $merchant_id = 'XXXXXXX';
    protected $language = 'AZ';
    const PORT = 5443;


    public function __construct()
    {
        if (Storage::disk('local')->exists('testmerchant.csr')) {
            $this->cert = storage_path('/'.$this->cert);
        } else {
            throw new \Exception("Certificate does not exists: $this->cert");
        }

        if (Storage::disk('local')->exists('merchant_name.key')) {
            $this->key = storage_path('/'.$this->key);
        } else {
            throw new \Exception("Key does not exists: $this->key");
        }
    }

    public function index(){
        return 'index';
    }

    public function curl($xml){
        $url = $this->serviceUrl;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_PORT, self::PORT);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");


        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        curl_setopt($ch, CURLOPT_SSLCERT, $this->cert);
        curl_setopt($ch, CURLOPT_SSLKEY, $this->key);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);

        //Error handling and return result
        $data = curl_exec($ch);
        if ($data === false) {
            $result = curl_error($ch);
        } else {
            $result = $data;
        }
        // Close handle
        curl_close($ch);

        return $result;
    }

    public function createOrder(Request $request){

        $request->validate([
            'amount' => 'required|numeric',
        ]);
        //echo header("Location: ");

        $order_data = array(
            'merchant' => $this->merchant_id,
            'amount' => $request->amount*100,
            'currency' => 944,
            'description' => Auth::guard('tuser')->user()->name." ".Auth::guard('tuser')->user()->surname." | Balansın artırılması",
            'lang' => 'AZ'
        );


        $xml = '<?xml version="1.0" encoding="UTF-8"?>
                <TKKPG>
                      <Request>
                               <Operation>CreateOrder</Operation>
                                <Language>'.$order_data['lang'].'</Language>
                                <Order>
                                    <OrderType>Purchase</OrderType>
                                    <Merchant>'.$this->merchant_id.'</Merchant>
                                    <Amount>'.$order_data['amount'].'</Amount>
                                    <Currency>'.$order_data['currency'].'</Currency>
                                    <Description>'.$order_data['description'].'</Description>
                                    <ApproveURL>https://mywebsite.az/api/kapital/approve/'.Auth::guard('tuser')->user()->id.'</ApproveURL>
                                    <CancelURL>https://mywebsite.az/api/kapital/cancel/'.Auth::guard('tuser')->user()->id.'</CancelURL>
                                    <DeclineURL>https://mywebsite.az/api/kapital/decline/'.Auth::guard('tuser')->user()->id.'</DeclineURL>
                              </Order>
                      </Request>
                </TKKPG>
        ';
        //return $xml;

        $result = $this->curl($xml);

        return $this->handleCurlResponse($order_data,$result);

       // dd($result);
        // $result;
    }

    public function handleCurlResponse($inital_data, $data){
        $oXML = new SimpleXMLElement($data);
        //dd($oXML);

        $OrderID = $oXML->Response->Order->OrderID;
        $SessionID = $oXML->Response->Order->SessionID;
        $paymentBaseUrl = $oXML->Response->Order->URL;


        Payment::create([
            'user_id' => Auth::guard('tuser')->user()->id,
            'amount' => $inital_data['amount'],
            'order_id' => $OrderID,
            'session_id' => $SessionID,
            'payment_url' => $paymentBaseUrl,
            'status_code' => $oXML->Response->Status,
            'order_description' => $inital_data['description'],
            'currency' => $inital_data['currency'],
            'language_code' => $inital_data['currency'],
        ]);

        $redirectUrl = $paymentBaseUrl."?ORDERID=".$OrderID."&SESSIONID=".$SessionID."&";
        //dd($redirectUrl);
        //echo $redirectUrl;
        return redirect()->to($redirectUrl);;

        //return header("Location: ");

    }

    public function approveUrl(Request $request){

        $xmlmsg = new SimpleXMLElement($request->xmlmsg);

        $getPaymentRow = Payment::where('order_id', '=', $xmlmsg->OrderID)->first();
        
        $user = User::find($getPaymentRow->user_id);
        if($getPaymentRow){
            $getPaymentRow->update([
                'order_status' => $xmlmsg->OrderStatus,
            ]);
            User::where(['id'=>$getPaymentRow->user_id])->update(['balance'=>$user->balance+$getPaymentRow->amount*0.01]);
            $this->getOrderStatus($getPaymentRow);
        }

        return redirect('/balans')->with('flash_message_success','Ödəniş uğurla başa çatmışdır');
    }

    public function cancelUrl(Request $request){
        //echo $request->xmlmsg;
        $xmlmsg = new SimpleXMLElement($request->xmlmsg);


        $getPaymentRow = Payment::where('order_id', '=', $xmlmsg->OrderID)->first();

        if($getPaymentRow){
            $getPaymentRow->update([
                'order_status' => $xmlmsg->OrderStatus,
            ]);
        }

        return redirect('/balans')->with('flash_message_error','Ödəniş ləğv edildi');
    }

    public function declineUrl(Request $request){
        //dd($request->all());

        if ($request->filled('xmlmsg')){
            $xmlmsg = new SimpleXMLElement($request->xmlmsg);
            //dd($xmlmsg->OrderStatus);
            $getPaymentRow = Payment::where('order_id', '=', $xmlmsg->OrderID)->first();
            if($getPaymentRow){
                $getPaymentRow->update([
                    'order_status' => $xmlmsg->OrderStatus,
                ]);
            }
        }

        return redirect('/balans')->with('flash_message_error','Ödəniş rədd edildi');
    }

    //Internet shop must perform the Get Order Status operation for the security purposes and decide whether to provide the service or not depending on the response.
    public function getOrderStatus($data){

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
                <TKKPG>
                    <Request>
                        <Operation>GetOrderStatus</Operation>
                        <Language>'.$this->language.'</Language>
                        <Order>
                            <Merchant>'.$this->merchant_id.'</Merchant>
                            <OrderID>'.$data->order_id.'</OrderID>
                        </Order>
                        <SessionID>'.$data->session_id.'</SessionID>
                    </Request>
                </TKKPG>';

        $response = $this->curl($xml);

        $xmlmsg = new SimpleXMLElement($response);
        //dd($xmlmsg->Response->Status);
        $getPaymentRow = Payment::where('order_id', '=', $xmlmsg->Response->Order->OrderID)->first();
        if($getPaymentRow){
            $getPaymentRow->update([
                'order_check_status' => $xmlmsg->Response->Order->OrderStatus,
                'status_code' => $xmlmsg->Response->Status,
            ]);
        }

        return $response;

    }

    //paymentLogs in admin
    public function paymentLogs(){
        $rows = Payment::latest()->paginate(20);

        return view('back.settings.payment_logs', compact('rows'));
    }
}
