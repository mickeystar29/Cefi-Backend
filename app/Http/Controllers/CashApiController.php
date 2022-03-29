<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use anlutro\LaravelSettings\Facade as Setting;

use App\Models\BankPayout;

use App\Libs\Flutterwave\library\Transfer;
use App\Libs\Flutterwave\library\Misc;
use App\Models\PayHistory;
use App\Models\User;
use App\Models\Withdraw;
use App\Repositories\BankChargeRepository;
use App\Repositories\MobileChargeRepository;
use App\Repositories\MobilePayoutRepository;
use App\Repositories\UserRepository;

class CashApiController extends Controller
{
    /** @var  UserRepository */
    private $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function mobileCharge(Request $request, MobileChargeRepository $moileChargeRepo)
    {
        $this->validate($request, [
            'currency' => 'required',
            'network' => 'required|in:mobile_money_rwanda,mobile_money_uganda,mobile_money_zambia,mobile_money_ghana,mobile_money_franco,mpesa',
            // 'type' => 'required_if:network,mobile_money_ghana|in:MTN,VODAFONE,TIGO',
            'amount' => 'required',
            'email' => 'required|email',
            'phone_number' => 'required',
            'fullname' => 'required',
        ]);

        try {
            $result = $moileChargeRepo->charge($request);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }

        return response()->json(['result' => $result]);
    }

    public function bankCharge(Request $request, BankChargeRepository $repo)
    {
        $this->validate($request, [
            'type' => 'required|in:debit_ng_account,debit_uk_account,ussd',
            // 'account_bank' => 'required_if:type,ussd|in:044,050,070,011,214,058,030,082,221,232,032,033,215,035,057',
            'account_bank' => 'required',
            // 'account_number' => 'required_unless:type,ussd',
            'currency' => 'required',//'required_if:type,ussd|in:NGN|in:NGN,GBP',
            'amount' => 'required',
            'email' => 'required|email',
            'phone_number' => 'required',
            'fullname' => 'required',
        ]);

        try {
            $result = $repo->charge($request);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }

        return response()->json(['result' => $result]);

    }

    public function mobilePayout(Request $request, MobilePayoutRepository $moilePayoutRepo)
    {
        $this->validate($request, [
            'currency' => 'required',
            'amount' => 'required',
            'type' => 'in:MPS,MTN,TIGO,VODAFONE,AIRTEL',
            'email' => 'required|email',
            'phone_number' => 'required',
            'fullname' => 'required',
        ]);

        try {
            $result = $moilePayoutRepo->payout($request);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }

        return response()->json(['result' => $result]);
    }

    public function bankPayout(Request $request)
    {
        $this->validate($request, [
            'account_bank' => 'required',
            'account_number' => 'required',
            'currency' => 'required',
            'amount' => 'required',
            'email' => 'required|email',
            'fullname' => 'required',
        ]);

        $data = array(
            "account_bank"=> $request->account_bank,
            "account_number"=> $request->account_number,
            "amount"=> $request->amount,
            "currency"=> $request->currency,
            "debit_currency"=> $request->currency
        );

        $getdata = array(
            //"reference"=>"edf-12de5223d2f32434753432"
             "id"=>"BIL136",
             "product_id"=>"OT150"
        );

        $listdata = array(
            'status'=>'failed'
        );

        $feedata = array(
            'currency'=> $request->currency, //if currency is omitted. the default currency of NGN would be used.
            'amount'=> 1000
        );

        $payment = new Transfer();
        $result = $payment->singleTransfer($data);//initiate single transfer payment
        // $getTransferFee = $payment->getTransferFee($feedata);

        if($result['status'] == 'error') {
            return response()->json(['error' => $result['message']], 500);
        }

        if(isset($result['data'])){
            $id = $result['data']['id'];
            BankPayout::create([
                'user_id' => Auth::user()->id,
                'currency' => $request->currency,
                'account_bank' => $request->account_bank,
                'account_number' => $request->account_number,
                'amount' => $request->amount,
                'email' => $request->email,
                'full_name' => $request->fullname,
                'fee' => 0,
                'txn_id' => $id
            ]);
            return response()->json(['result' => $result]);
        } else {
            return response()->json(['error' => $result], 500);
        }

    }

    public function rate(Request $request)
    {
        $this->validate($request, [
            'from' => 'required',
            'to' => 'required'
        ]);

        $data = array(
            'from' => $request->from,
            'to' => $request->to
        );

        $misc = new Misc();
        $result = $misc->rate($data);
        $result['data']['fee'] = Setting::get('cash_conversation_fee', 8);

        return response()->json(['result' => $result]);
    }

    public function payoutFee(Request $request)
    {
        $this->validate($request, [
            'amount' => 'required',
            'currency' => 'required',
            'type' => 'required|in:mobilemoney,account'
        ]);

        $data = array(
            'amount' => $request->amount,
            'currency' => $request->currency,
            'type' => $request->type
        );

        $payout = new Transfer();
        $result = $payout->getTransferFee($data);

        return response()->json(['result' => $result]);
    }

    public function pay(Request $request)
    {
        $this->validate($request, [
            'receiver' => 'required|email',
            'amount' => 'required'
        ]);

        $amount = $request->amount;

        try {
            $users = $this->userRepository->all(
                [ 'email' => $request->receiver ]);
            if (count($users) == 0) {
                return response()->json(['status' => false, 'error' => 'Can\'t find receiver.']);
            }
            $receiver = $users[0];
            $this->userRepository->pay($amount, $receiver);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'error' => $th->getMessage()], 500);
        }

        return response()->json(['status' => true]);
    }

    public function withdrawFee()
    {
        $fee = Setting::get('paypal_withdraw_fee', 25);

        return response()->json(['fee' => $fee]);
    }

    public function withdraw(Request $request)
    {
        $this->validate($request, [
            'to' => 'required|email',
            'amount' => 'required'
        ]);

        $user = Auth::user();
        $amount = $request->amount;
        if($amount == 0)
            return response()->json(['status' => false, 'error' => 'Invalidate amount.'], 500);
        if($user->balance < $amount)
            return response()->json(['status' => false, 'error' => 'Insufficient amount.'], 500);

        Withdraw::create([
            'user_id' => $user->id,
            'to' => $request->to,
            'kind' => 'Cash',
            'amount' => $amount,
        ]);

        return response()->json(['status' => true, 'message' => 'Sent your withdraw request. It will take 2 or 3 business days.']);
    }

    public function mobileChargeHistory()
    {
        $user = Auth::user();
        $charges = $user->mobileCharges;

        return response()->json(['result' => $charges]);
    }

    public function bankChargeHistory()
    {
        $user = Auth::user();
        $charges = $user->bankCharges;

        return response()->json(['result' => $charges]);
    }

    public function mobilePayoutHistory()
    {
        $user = Auth::user();
        $payouts = $user->mobilePayouts;

        return response()->json(['result' => $payouts]);
    }

    public function bankPayoutHistory()
    {
        $user = Auth::user();
        $payouts = $user->bankPayouts;

        return response()->json(['result' => $payouts]);
    }

    public function payHistory()
    {
        $userId = Auth::id();
        $pays = PayHistory::where('sender_id', $userId)->orWhere('receiver_id', $userId)->get();

        foreach ($pays as $payment) {
            $payment['sender'] = User::find($payment->sender_id)->email;
            $payment['receiver'] = User::find($payment->receiver_id)->email;
        }

        return response()->json(['result' => $pays]);
    }
}
