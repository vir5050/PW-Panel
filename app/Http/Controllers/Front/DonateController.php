<?php


namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Requests\BanklogRequest;
use App\Mail\BankTransfer;
use App\Models\BankLog;
use App\Models\Paymentwall;
use App\Models\Paypal;
use App\Models\ServiceLog;
use App\Models\ShopLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Omnipay\Omnipay;
use Paymentwall_Config;
use Paymentwall_Widget;

class DonateController extends Controller
{
    protected $gateway;

    public function __construct()
    {
        $this->gateway = Omnipay::create('PayPal_Rest');
        $this->gateway->initialize([
            'clientId' => config('pw-config.payment.paypal.client_id'),
            'secret' => config('pw-config.payment.paypal.secret'),
            'testMode' => config('pw-config.payment.paypal.sandbox'),
        ]);
    }


    public function getPaypalIndex()
    {
        return view('front.donate.paypal.index');
    }

    public function postPaypalSubmit(Request $request)
    {
        $transaction = $this->gateway->purchase([
            'amount' => number_format($request->dollars, 0),
            'currency' => config('pw-config.payment.paypal.currency'),
            'description' => __('donate.paypal.description', ['amount' => $request->dollars, 'currency' => config('pw-config.currency_name')]),
            'returnUrl' => route('app.donate.paypal.complete'),
            'cancelUrl' => route('app.donate.paypal'),
        ]);

        $response = $transaction->send();

        if ($response->isRedirect()) {
            $response->redirect();
        } else {
            echo $response->getMessage();
        }
    }

    public function postPaypalComplete(Request $request)
    {
        $complete = $this->gateway->completePurchase([
            'transactionReference' => $request->paymentId,
            'payerId' => $request->PayerID,
        ]);

        $response = $complete->send();
        $data = $response->getData();

        if ($data['state'] === 'approved') {
            $user = Auth::user();
            $amount = round($data['transactions'][0]['amount']['total']);

            $payment_amount = config('pw-config.payment.paypal.double') ? ($amount * config('pw-config.payment.paypal.currency_per')) * 2 : $amount * config('pw-config.payment.paypal.currency_per');

            Paypal::create([
                'user_id' => $user->ID,
                'transaction_id' => $data['id'],
                'amount' => $payment_amount
            ]);

            $user->money = $user->money + $payment_amount;
            $user->save();
        }

        return redirect()->route('app.donate.paypal')->with('success', __('donate.paypal.success'));
    }

    public function getBankIndex()
    {
        //$getPending = BankLog::where(['loginid' => Auth::user()->name, 'status' => 'pending'])->get();
        //$getPending = BankLog::whereLoginid(Auth::user()->name)->whereStatus('pending')->count('loginid');
        return view('front.donate.bank.index');
    }

    public function postBank(BanklogRequest $request)
    {
        $input = $request->all();
        BankLog::create($input);
        $bank = [
            'fullname' => $input['fullname'],
            'banknum' => $input['banknum'],
            'loginid' => $input['loginid'],
            'gameID' => $input['gameID'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'type' => $input['type'],
            'bankname' => $input['bankname'],
            'amount' => $input['amount'],
        ];
        Mail::to(config('mail.from.address'))->locale(Auth::user()->language)->send(new BankTransfer($bank));
        return redirect(route('app.donate.bank'))->with('success', __('donate.guide.bank.form.success'));
    }

    public function getPaymentwallIndex()
    {
        Paymentwall_Config::getInstance()->set(array(
            'api_type' => Paymentwall_Config::API_VC,
            'public_key' => config('pw-config.payment.paymentwall.project_key'),
            'private_key' => config('pw-config.payment.paymentwall.secret_key')
        ));

        $userId = Auth::user()->ID;
        $userEmail = Auth::user()->email;
        $userRegDate = Auth::user()->created_at;
        $userLastUpdate = Auth::user()->last_update;
        $userName = Auth::user()->name;
        $widgetCode = config('pw-config.payment.paymentwall.widget_code');
        $products = array();
        $extraParams = array(
            'email' => $userEmail,
            'history[registration_date]' => $userRegDate,
            'customer[username]' => $userName,
            'history[membership_date]' => $userLastUpdate,
        );
        $PWWidget = new Paymentwall_Widget($userId, $widgetCode, $products, $extraParams);
        $widget = $PWWidget->getHtmlCode([
            'width' => config('pw-config.payment.paymentwall.widget_width'),
            'height' => config('pw-config.payment.paymentwall.widget_high')
        ]);
        return view('front.donate.paymenwall.index', [
            'widget' => $widget,
        ]);
    }

    public function getHistoryIndex()
    {
        $logbank = BankLog::whereGameid(Auth::user()->ID)->paginate(10);
        $pws = Paymentwall::whereUserid(Auth::user()->ID)->paginate(10);
        $ingamelogs = ServiceLog::whereUserid(Auth::user()->ID)->paginate(10);
        $shoplogs = ShopLog::whereUserid(Auth::user()->ID)->paginate(10);
        $paypals = Paypal::whereUserId(Auth::user()->ID)->paginate(10);
        $user = new User();
        return view('front.donate.history.index', [
            'banks' => $logbank,
            'pws' => $pws,
            'user' => $user,
            'ingamelogs' => $ingamelogs,
            'shoplogs' => $shoplogs,
            'paypals' => $paypals
        ]);
    }
}
