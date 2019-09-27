<?php
/*
 - Author : GoldenSource.iR 
 - Module Designed For The : payping.ir
 - Mail : Mail@GoldenSource.ir
*/
use WHMCS\Database\Capsule;
if(isset($_REQUEST['invoiceId']) && is_numeric($_REQUEST['invoiceId'])){
    require_once __DIR__ . '/../../init.php';
    require_once __DIR__ . '/../../includes/gatewayfunctions.php';
    require_once __DIR__ . '/../../includes/invoicefunctions.php';
    $gatewayParams = getGatewayVariables('payping');
    if(isset($_REQUEST['refid'], $_REQUEST['callback']) && $_REQUEST['callback'] == 1){
        $invoice = Capsule::table('tblinvoices')->where('id', $_REQUEST['invoiceId'])->where('status', 'Unpaid')->first();
        if(!$invoice){
            die("Invoice not found");
        }
        $amount = ceil($invoice->total / ($gatewayParams['currencyType'] == 'IRT' ? 1 : 10));
        $realAmount = $invoice->total;
        $data = array('refId' => $_GET['refid'], 'amount' => $amount);
        try {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.payping.ir/v1/pay/verify",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => array(
                    "accept: application/json",
                    "authorization: Bearer " . $gatewayParams['tokenCode'],
                    "cache-control: no-cache",
                    "content-type: application/json",
                ),
            ));
            $response = curl_exec($curl);
            $err = curl_error($curl);
            $header = curl_getinfo($curl);
            curl_close($curl);
            if ($err) {
                logTransaction('payping', array('Get' => $_GET, 'WebServiceBody' => $response, 'WebServiceHeader' => $header ,'error' => 'Curl Error: '.$err), 'Unsuccessful'); # Save to Gateway Log: name, data array, status
            } else {
                if ($header['http_code'] == 200) {
                    $response = json_decode($response, true);
                    if (isset($_GET["refid"]) and $_GET["refid"] != '') {
                        checkCbTransID($_GET["refid"]);
                        addInvoicePayment($invoice->id, $_GET["refid"], $realAmount, 0, 'payping');
                        logTransaction('payping', array('Get' => $_GET, 'WebServiceBody' => $response, 'WebServiceHeader' => $header ,), 'Successful');
                    } else {
                        logTransaction('payping', array('Get' => $_GET, 'WebServiceBody' => $response, 'WebServiceHeader' => $header ,'error' => 'refid is empty'), 'Unsuccessful');
                    }
                } else {
                    logTransaction('payping', array('Get' => $_GET, 'WebServiceBody' => $response, 'WebServiceHeader' => $header ,'error' => payping_status_message($header['http_code']) . '(' . $header['http_code'] . ')' ), 'Unsuccessful');
                }

            }
        } catch (Exception $e){
            logTransaction('payping', array('Get' => $_GET, 'WebServiceBody' => $response, 'WebServiceHeader' => $header ,'error' => 'connection Error : '.$e->getMessage()), 'Unsuccessful');
        }
        header('Location: ' . $gatewayParams['systemurl'] . '/viewinvoice.php?id=' . $invoice->id);
    } else if(isset($_SESSION['uid'])){
        $invoice = Capsule::table('tblinvoices')->where('id', $_REQUEST['invoiceId'])->where('status', 'Unpaid')->where('userid', $_SESSION['uid'])->first();
        if(!$invoice){
            die("Invoice not found");
        }
        $client = Capsule::table('tblclients')->where('id', $_SESSION['uid'])->first();
        $amount = ceil($invoice->total / ($gatewayParams['currencyType'] == 'IRT' ? 1 : 10));
        $data = array(
            'payerName' => trim(sprintf('%s %s', $client->firstname, $client->lastname)), 
            'Amount' => $amount,
            'payerIdentity'=> $client->email, 
            'returnUrl' => $gatewayParams['systemurl'] . '/modules/gateways/payping.php?invoiceId=' . $invoice->id . '&callback=1', 
            'Description' => sprintf('پرداخت فاکتور #%s', $invoice->id),
            'clientRefId' => $invoice->id,
        );
        try {
            $curl = curl_init();
            curl_setopt_array($curl, array(CURLOPT_URL => "https://api.payping.ir/v1/pay",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => array(
                    "accept: application/json",
                    "authorization: Bearer " . $gatewayParams['tokenCode'],
                    "cache-control: no-cache",
                    "content-type: application/json"),
                )
            );
    
            $response = curl_exec($curl);
            $header = curl_getinfo($curl);
            $err = curl_error($curl);
            curl_close($curl);
    
            if ($err) {
                $return =  "cURL Error #:" . $err;
            } else {
                if ($header['http_code'] == 200) {
                    $response = json_decode($response, true);
                    if (isset($response["code"]) and $response["code"] != '') {
                        header("Location: " . sprintf('https://api.payping.ir/v1/pay/gotoipg/%s', $response["code"]));
                    } else {
                        $return = ' تراکنش ناموفق بود- شرح خطا : عدم وجود کد ارجاع ';
                    }
                } elseif ($header['http_code'] == 400) {
                    $return = ' تراکنش ناموفق بود- شرح خطا : ' . implode('. ',array_values (json_decode($response,true)));
                } else {
                    $return = ' تراکنش ناموفق بود- شرح خطا : ' . payping_status_message($header['http_code']) . '(' . $header['http_code'] . ')';
                }
            }
        } catch (Exception $e){
            $return = ' تراکنش ناموفق بود- شرح خطا سمت برنامه شما : ' . $e->getMessage();
        }
        if(isset($return) && !empty($return)){
            echo $return;
            die;
        }
    }
    return;
}

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

function payping_status_message($code) {
	switch ($code){
		case 200 :
			return 'عملیات با موفقیت انجام شد';
			break ;
		case 400 :
			return 'مشکلی در ارسال درخواست وجود دارد';
			break ;
		case 500 :
			return 'مشکلی در سرور رخ داده است';
			break;
		case 503 :
			return 'سرور در حال حاضر قادر به پاسخگویی نمی‌باشد';
			break;
		case 401 :
			return 'عدم دسترسی';
			break;
		case 403 :
			return 'دسترسی غیر مجاز';
			break;
		case 404 :
			return 'آیتم درخواستی مورد نظر موجود نمی‌باشد';
			break;
	}
	return null;
}

function payping_MetaData()
{
    return array(
        'DisplayName' => 'ماژول پرداخت آنلاین پی‌پینگ برای WHMCS',
        'APIVersion' => '1.0',
    );
}

function payping_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'پی‌پینگ',
        ),
        'currencyType' => array(
            'FriendlyName' => 'نوع ارز',
            'Type' => 'dropdown',
            'Options' => array(
                'IRR' => 'ریال',
                'IRT' => 'تومان',
            ),
        ),
        'tokenCode' => array(
            'FriendlyName' => 'کد توکن اختصاصی',
            'Type' => 'text',
            'Size' => '255',
            'Default' => '',
            'Description' => 'کد api دریافتی از سایت PayPing.ir',
        ),
    );
}

function payping_link($params)
{
    $htmlOutput = '<form method="GET" action="modules/gateways/payping.php">';
    $htmlOutput .= '<input type="hidden" name="invoiceId" value="' . $params['invoiceid'] .'">';
    $htmlOutput .= '<input type="submit" value="' . $params['langpaynow'] . '" />';
    $htmlOutput .= '</form>';
    return $htmlOutput;
}
