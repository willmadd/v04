<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use DateTime;
use DateTimeZone;

class PnrController extends Controller
{
    public function convertPnr(Request $request)
    {
        $host = parse_url(request()->headers->get('referer'), PHP_URL_HOST);
        if ($host !== "www.pnrdev.com" && $host !== "pnrdev.com" && $host !== "www.pnrconverter.com" && $host !== "pnrconverter.com"&& $host !=="localhost"){
            return response()->json([
                'message' => 'unauthorised request'
            ], 403);
        }

        //get pnr post field
        $pnr = $request->post('pnr');
        //replace special characters
        $pnr = str_replace(['Â', '$', '.#', '.'], " ", $pnr);
        $pnr = trim(preg_replace("~\s*\R\s*~", "\n", $pnr));
        //replace double linebreaks with single line breaks
        $pnr = str_replace("\r\n\r\n","\r\n",$pnr);
        $pnr = explode("\n", $pnr);




        
        //define final output as an array
        $finalOutput = Array();
        $finalOutput['names'] = Array();
        $finalOutput['flights'] = Array();

        $finalOutput['meta'] = Array(
            "pnr"=>$this->getPNR($pnr),  
        );


        $i = 0;
        foreach($pnr as $pnrLine)
        {
            //remove any * that appear before position 20 in a pnr
            $pnrLine = str_replace('*', " ", substr($pnrLine, 0,20)).substr($pnrLine, 20);
            //condense spaces and tabs to single space
            $pnrLine = preg_replace('/\h+/', ' ', $pnrLine);
            
            //search for 5 numbers together in first bit of pnr e.g. G37801 and put a space to get G3 7801
            if(preg_match('/[0-9]{5}/', substr($pnrLine, 0, 10))){
                preg_match('/[0-9]{5}/', $pnrLine, $matches, PREG_OFFSET_CAPTURE);
                $offset = $matches[0][1];
                $test=$matches[0];
                $pnrLine = substr_replace( $pnrLine, " ", $offset+1, 0 );
            }
            
            
            //find names
            $names = $this->getNames($pnrLine);

            if($names){
                array_push($finalOutput['names'], $names);
                $names = null;
            }

            $operatedBy = $this->getOperatedBy($pnrLine);


            if($operatedBy){
                $finalOutput['flights'][$i-1]['flt']['operated_by'] = $operatedBy;
                $operatedBy = null;
            }

            //remove preceeding line numbers
            $pnrLine = preg_replace('/^[0-9]+\s/', '', $pnrLine);
            //get iatacode
            $iatacode = substr($pnrLine, 0,2);
            //search database and pull back correct airline record
            $airlineQuery = DB::table('airlinemaster')->where('iatacode', $iatacode)->first();
            //search

            $bookingClass = $this->getBookingClass($pnrLine);
            $departureAirportCode = $this->getAirportCodes($pnrLine);
            $departure = $departureAirportCode["departing_from"];
            $arrival = $departureAirportCode["arriving_at"];

            if($bookingClass&&$departureAirportCode&&$departure&&$arrival&&!$names&&!$operatedBy&&preg_match('#[0-9]#',substr($pnrLine, 0, 6))===1 && preg_match('#[0-9]{5}#',$pnrLine)!==1){
                
                $departureAirportQuery = DB::table('airportdata')->select('airportname','cityname', 'countryname', 'airportcode', 'latitude', 'longitude', 'timezone')->where('airportcode', $departure)->first();
                $arrivalAirportQuery = DB::table('airportdata')->select('airportname','cityname', 'countryname', 'airportcode', 'latitude', 'longitude', 'timezone')->where('airportcode', $arrival)->first();

                $departureAirportQuery->{"timezoneshort"} = $this->timezone_abbr_from_name($departureAirportQuery->timezone);
                $arrivalAirportQuery->{"timezoneshort"} = $this->timezone_abbr_from_name($arrivalAirportQuery->timezone);
//
                if($bookingClass){
                    $bookingCabin = $airlineQuery->$bookingClass;
                }else{
                    $bookingCabin = null;
                }
                $aircraft = $this->getAircraft($pnrLine);

                if ($aircraft){
                    $aircraftQuery = DB::table('aircraft')->select('aircraft')->where('iatacode', $aircraft)->first();
                    if (count($aircraftQuery)){
                        $aircraft = $aircraftQuery->aircraft;
                    }else{
                        $aircraft = null;
                    }
                }

                $times = $this->getTimeAndDate($pnrLine, $departureAirportQuery->timezone, $arrivalAirportQuery->timezone);
                $arrivalDateTime = $times['arrival'];
                $departureDateTime = $times['departure'];
                $distance=$this->getFlightDistance($departureAirportQuery->longitude, $departureAirportQuery->latitude, $arrivalAirportQuery->longitude, $arrivalAirportQuery->latitude);
                $duration = $this->getFlightDuration($departureDateTime['string'], $departureAirportQuery->timezone, $arrivalDateTime['string'], $arrivalAirportQuery->timezone);
                $c02 = $this->calculcatec02($distance, $bookingCabin);
                
                $flightLineOutput= Array(
                    'flightNo' => $this->getFlightNo($pnrLine),
                    'iatacode'=>$iatacode,
                    "name" => $airlineQuery->airline_name,
                    "operated_by"=>$airlineQuery->airline_name,
                    'cabin'=>  $bookingCabin,
                    "class" => $bookingClass,
                    "aircraft"=>$aircraft,
                    "departure" => $departureDateTime,
                    "arrival" => $arrivalDateTime,
                    "transit_time" => (object) array(),
                    "duration" => $duration,
                    "distance" => $distance,
                    "co2" => $c02,
                    "svg-logo-high-res" => "https://www.pnrconverter.com/images/airlines/".strtolower($iatacode).".svg",
                    "png-logo-low-res" => "https://www.pnrconverter.com/images/airlines/png/150/".strtolower($iatacode).".png"
                );

    
                $flight_entry = array(
                    "dep" => $departureAirportQuery,
                    "arr" => $arrivalAirportQuery,
                    "flt" => $flightLineOutput,
                );
    
                array_push($finalOutput['flights'], $flight_entry);
                $i++;
            }

        }
        $j = 0;
        foreach($finalOutput['flights'] as $flight)
        {
            if($j+1 < count($finalOutput['flights'])){
                $currentFlightArrivalTime = $flight['flt']['arrival']['string'];
                $nextFlightDepartureTime = $finalOutput['flights'][$j+1]['flt']['departure']['string'];
                $datetime1 = new DateTime($currentFlightArrivalTime);
                $datetime2 = new DateTime($nextFlightDepartureTime);
                $transitInterval = $datetime1->diff($datetime2);
                
                // print_r( $transitInterval->i );

                $transitTime = array(
                    "minutes" => ($transitInterval->i),
                    "hours" => ($transitInterval->h),
                    "days" => ($transitInterval->d),
                    "months" => ($transitInterval->m),
                );
                $finalOutput['flights'][$j]['flt']['transit_time'] = $transitTime;
            }

            $j++;
        }

        return response()->json([
            'flightData' => $finalOutput
        ], 200, [], JSON_UNESCAPED_SLASHES);
    }

    protected function getBookingClass($flightLine){
        preg_match('/(?<=(\d|\s))([A-Z])(?=\s[0-9]{2}[A-Z]{3})/', substr($flightLine, 0, 23), $matches);//searched for single letter followed by a space
        if($matches){
            $matches[0] = preg_replace("/[0-9]+/", "", $matches[0]);
            return trim($matches[0]);
        }else{
            return null;
        }
      }

      protected function getAirportCodes($flightLine)
      {
        $departing_from;
        $arriving_at;
          preg_match('/\b[A-Z]{6}\b/', $flightLine, $matches);
          if($matches){
              $departing_from = substr($matches[0], 0, 3);
              $arriving_at = substr($matches[0], 3, 3);
          }else{
              preg_match('/\b[A-Z][A-Z][A-Z]\s[A-Z][A-Z][A-Z]\b/', $flightLine, $matches);
                  if($matches){
                      $departing_from = substr($matches[0], 0, 3);
                      $arriving_at = substr($matches[0], 4, 3);
                }else{
                    preg_match('/\b\w{5}\s+[A-Z]{3}\s\w{1,2}\s[A-Z]{3}\b/', $flightLine, $matches);
                    if($matches){
                        preg_match_all('/\b[A-Z]{3}\b/', $matches[0], $matchesTwo);
                        $departing_from = $matchesTwo[0][0];
                        $arriving_at = $matchesTwo[0][1];
                    }else{
                        $departing_from = null;
                        $arriving_at = null;
                    }
                }
          }

          $destinations = array();
              $destinations['departing_from'] = $departing_from;
              $destinations['arriving_at'] = $arriving_at;
  
          return $destinations;
      }

    protected function getTimeAndDate($flightLine, $depTimeZone, $arrTimeZone)
    {
        $timings = array();
        $departure_date = null;
        $arrival_date = null;
        preg_match_all('/[0-9]+((JAN)|(FEB)|(MAR)|(APR)|(MAY)|(JUN)|(JUL)|(AUG)|(SEP)|(OCT)|(NOV)|(DEC))/', $flightLine, $datematches);
        if($datematches){
            // print_r($datematches);
            $departure_date = $datematches[0][0];

                //if two dates found in the format 02OCT then assign one to arrival date
                if(count($datematches[0])>=2){
                    $arrival_date = $datematches[0][1];

                    $futureArrDate =  $arrival_date." ".date('Y', strtotime('+1 year', strtotime($arrival_date)));
                    $today = strtotime('today UTC');
                    $today = date('Y-m-d', strtotime('today UTC'));
                    $datetime2 = date_create($futureArrDate);
                    $datetime1 = date_create($today);
                    $interval = date_diff($datetime1, $datetime2);
                    if(($interval->y)>0){
                    $arrival_date = date('Y-m-d', strtotime('-1 year', strtotime($futureArrDate)));
                    }else{
                        $arrival_date = date('Y-m-d', strtotime($futureArrDate));
                    }

                }
                // this below line works, but dons't recognise AI 127   05AUG 1 HYDDEL  HK1   810P     855P1110P      correctly
                // preg_match_all('/[0-9]{3,4}(A|P|N)|(\b[0-9]{4}\b)|(\b[0-9]{2}:[0-9]{2}\b)/', substr($flightLine, 10, 70), $timematches, PREG_SET_ORDER);
                preg_match_all('/(?<=\s|[APN])[0-9]{3,4}(A|P|N)(?=\s|\d|\b)|(\b[0-9]{4}\b)|(\b[0-9]{2}:[0-9]{2}\b)/', substr($flightLine, 10, 70), $timematches, PREG_SET_ORDER);
                if (count($timematches)===3){
                    array_shift($timematches);
                }

                // print_r($timematches);

                // if ($timematches[2][0]) {
                //     array_splice($timematches, 0, 1);
                // }
        

                $departure_time = $timematches[0][0];
                $arrival_time = $timematches[1][0];
            
                if(preg_match('/[0-9]{3,4}(A|P|N)/', $departure_time)){
                    $departure_time = substr_replace($departure_time, ':', -3, 0);
                }else{
                    $departure_time = substr_replace($departure_time, ':', 2, 0);
                }

                $departure_time = str_replace("A", "am", $departure_time);
                $departure_time = str_replace("P", "pm", $departure_time);

                if(preg_match('/[0-9]{3,4}(A|P|N)/', $arrival_time)){
                    $arrival_time = substr_replace($arrival_time, ':', -3, 0);
                }else{
                    $arrival_time = substr_replace($arrival_time, ':', 2, 0);
                }

                $arrival_time = str_replace("A", "am", $arrival_time);
                $arrival_time = str_replace("P", "pm", $arrival_time);

                $futureDate =  $departure_date." ".date('Y', strtotime('+1 year', strtotime($departure_date)));
                $today = strtotime('today UTC');
                $today = date('Y-m-d', strtotime('today UTC'));
                $datetime2 = date_create($futureDate);
                $datetime1 = date_create($today);
                $interval = date_diff($datetime1, $datetime2);
                if(($interval->y)>0){
                $departure_date = date('Y-m-d', strtotime('-1 year', strtotime($futureDate)));
                }else{
                    $departure_date = date('Y-m-d', strtotime($futureDate));
                }
                
                //start with the assumption that the  flight lands on the same day as it departs
                if (!$arrival_date){
                    $arrival_date = $departure_date;
                }

                // inital working code below

            // $departure_time = Array (
            // 'string' => date('Y-m-d H:i', strtotime($departure_time . ' ' . $departure_date)),
            // 'day' => date('D', strtotime($departure_time . ' ' . $departure_date)),
            // );

            $newDepTime = new DateTime($departure_time . ' ' . $departure_date, new DateTimeZone($depTimeZone));
            
            $departure_time = Array (
                // 'string' => date('Y-m-d H:i', strtotime($departure_time . ' ' . $departure_date)),
                'day' => date('D', strtotime($departure_time . ' ' . $departure_date)),
                'string' => $newDepTime->format('Y-m-d H:i'),
                'hr12'=> $newDepTime->format('g:i a'),
                'hr24'=> $newDepTime->format('H:i'),
                'UTC'=>$newDepTime->setTimezone(new DateTimeZone("UTC"))->format('Y-m-d H:i:sP'),
                'tz'=>$depTimeZone
                );

            



        
        // set arrival date
        
        $arrival_date_set_check = false;

        
        if ((preg_match('/\#[0-9]{4}/', $flightLine)) || (preg_match('/[0-9]{3,4}(A|N|P)\+1/', $flightLine))|| (preg_match('/[0-9]{4}\s[0-9]{4}\*/', $flightLine))|| (preg_match('/[0-9]{4}\s[0-9]{4}\+1/', $flightLine))|| (preg_match('/[0-9]{3,4}(A|N|P)\#1/', $flightLine))|| (preg_match('/\s\*[0-9]{4}\s/', $flightLine))|| (preg_match('/[0-9]{4}\s\#\s/', $flightLine)) || (preg_match('/[0-9]{4}\s\#[1]/', $flightLine))) {
            $arrival_date = date('d M Y', strtotime($departure_date . ' +1 days'));
            }
        
            if ((preg_match('/\*[0-9]{4}\s/', $flightLine)) || (preg_match('/[0-9]{3,4}(A|N|P)\+2/', $flightLine))|| (preg_match('/[0-9]{4}\+2/', $flightLine))) {
                $arrival_date = date('d M Y', strtotime($departure_date . ' +2 days'));
            }
        
            if ((preg_match('/\-[0-9]{4}\s/', $flightLine)) || (preg_match('/[0-9]{3,4}(A|N|P)\-1/', $flightLine))) {
                $arrival_date = date('d M Y', strtotime($departure_date . ' -1 days'));
            }
        
            
        
        
        //     if (strtotime($arrival_date) < strtotime('today UTC')) {
        //         $arrival_date = date('Y-m-d', strtotime('+1 year', strtotime($arrival_date)));
        //     } else {
        //         $arrival_date = date('Y-m-d', strtotime($arrival_date));
        //     }
        

// inital working code below


            // $arrival_time = Array (
            // 'string' => date('Y-m-d H:i', strtotime($arrival_time . ' ' . $arrival_date)),
            // 'day' => date('D', strtotime($arrival_time . ' ' . $arrival_date)),
            
            // );

            $newArrTime = new DateTime($arrival_time . ' ' . $arrival_date, new DateTimeZone($arrTimeZone));
            
            $arrival_time = Array (
                // 'string' => date('Y-m-d H:i', strtotime($departure_time . ' ' . $departure_date)),
                'day' => date('D', strtotime($arrival_time . ' ' . $arrival_date)),
                'string' => $newArrTime->format('Y-m-d H:i'),
                'hr12'=> $newArrTime->format('g:i a'),
                'hr24'=> $newArrTime->format('H:i'),
                'UTC'=>$newArrTime->setTimezone(new DateTimeZone("UTC"))->format('Y-m-d H:i:sP'),
                'tz'=>$arrTimeZone
                );


        
            $timings = array();
            $timings['departure'] = $departure_time;
            $timings['arrival'] = $arrival_time;
        
            
        }else{
            $timings['departure'] = null;
            $timings['arrival'] = null;
        }
        
        return $timings;
        
    }

    protected function getFlightDuration($departureDateTime, $departureTimezone, $arrivalDateTime, $arrivalTimezone)
    {

    
        $dateofdeparture = new DateTime($departureDateTime, new DateTimeZone($departureTimezone));
    
        $timedifferencefromutc = $dateofdeparture->format('P') . "\n";
    
        $dateofarrival = new DateTime($arrivalDateTime, new DateTimeZone($arrivalTimezone));
    
        $timedifferencefromutcarr = $dateofarrival->format('P') . "\n";
    
        $timedifferencefromutc = explode(":", $timedifferencefromutc);
    
        if ((substr($timedifferencefromutc[0], 0, 1) == '-') && (substr($timedifferencefromutc[1], 0, 1) != 0)) {
    
            $timedifferencefromutc[1] = '-' . $timedifferencefromutc[1];
        }
    
        $timedifferencefromutcarr = explode(":", $timedifferencefromutcarr);
    
        if ((substr($timedifferencefromutcarr[0], 0, 1) == '-') && (substr($timedifferencefromutcarr[1], 0, 1) != 0)) {
            $timedifferencefromutcarr[1] = '-' . $timedifferencefromutcarr[1];
        }
    
        if (substr($timedifferencefromutc[0], 0, 1) == '+') {
            $timedifferencefromutc[0] = str_replace("+", "", ($timedifferencefromutc[0]));
        }
    
        if (substr($timedifferencefromutcarr[0], 0, 1) == '+') {
            $timedifferencefromutcarr[0] = str_replace("+", "", ($timedifferencefromutcarr[0]));
        }
    
        $date1 = date_create($dateofarrival->format('r'));
        $date2 = date_create($dateofdeparture->format('r'));
        $diff = date_diff($date1, $date2, true);
    
        $flightDuration = array(
            "minutes" => ($diff->format('%i')),
            "hours" => ($diff->format('%h')),
        );
    
        return $flightDuration;
    }

    protected function getFlightDistance($depLong, $depLat, $arrLong, $arrLat)
    {
    
        $lat1 = $depLat;
        $lon1 = $depLong;
    
        $lat2 = $arrLat;
        $lon2 = $arrLong;
    
        $distance = (3958 * 3.1415926 * sqrt(($lat2 - $lat1) * ($lat2 - $lat1) + cos($lat2 / 57.29578) * cos($lat1 / 57.29578) * ($lon2 - $lon1) * ($lon2 - $lon1)) / 180);
    
        if ($distance > 12451) {
            $distance = (24901 - $distance);
        }
    
        $distanceArray = array(
            'miles' => round($distance),
            'km' => round($distance * 1.609344),
    
        );
    
        return $distanceArray;
    
    }

    protected function getFlightNo($flightLine){
        $flightLine = substr($flightLine, 2,70);
        preg_match('/\d+/', $flightLine, $matches);//find first number fhich is flight number
        $flight_no = $matches[0];
        return ltrim($flight_no, '0');
      }

      protected function getNames($flightLine){
        if(preg_match('/\b\d{1}\.\w{3,}\/\D+[\D]\b/', $flightLine)){
            return $flightLine;
        }else{
            return null;
        }
      }

      protected function getOperatedBy($flightLine){
        if(preg_match('/OPERATED BY\s/', $flightLine)){
            $arr = explode('OPERATED BY', $flightLine);
            return ltrim(ucwords(strtolower($arr[1])));
        }else{
            return null;
        }
      }

      protected function getAircraft($flightLine){
        $endOfLine = substr($flightLine, -9);
        if(preg_match('/\b\w{3}\b/', $endOfLine)){
            preg_match('/\b\w{3}\b/', $endOfLine, $matches);
            // echo $matches[0];
            return $matches[0];
        }else{
            return null;
        }
      }

    protected function calculcatec02($distance, $bookingCabin)
    {   
        $km = $distance['km'];
        $co2_per_km_rf;
        $co2_per_km_non_rf;
        if($km < 2000){
            switch ($bookingCabin){
                case "First":
                $co2_per_km_rf = 0.67376;
                $co2_per_km_non_rf = 0.34912;
                break;
    
                case "Business":
                $co2_per_km_rf = 0.24767;
                $co2_per_km_non_rf = 0.13091;
                break;
    
                case "Premium Economy":
                $co2_per_km_rf = 0.21055;
                $co2_per_km_non_rf = 0.1113125;
                break;

                case "Economy":
                $co2_per_km_rf = 0.16508;
                $co2_per_km_non_rf = 0.08728;
                break;
    
                default:
                $co2_per_km_rf = 0.16844;
                $co2_per_km_non_rf = 0.08905;
            }
        }else{
            switch ($bookingCabin){
                case "First":
                $co2_per_km_rf = 0.58711;
                $co2_per_km_non_rf = 0.31039;
                break;
    
                case "Business":
                $co2_per_km_rf = 0.42565;
                $co2_per_km_non_rf = 0.22503;
                break;
    
                case "Premium Economy":
                $co2_per_km_rf = 0.23484;
                $co2_per_km_non_rf = 0.12415;
                break;

                case "Economy":
                $co2_per_km_rf = 0.14678;
                $co2_per_km_non_rf = 0.07761;
                break;
    
                default:
                $co2_per_km_rf = 0.10131;
                $co2_per_km_non_rf = 0;
                }
            }
        $carbon = Array(
            'co2' => round(($co2_per_km_non_rf*$km)/100, 2),
            'co2_with_environmental_impact' =>round(($co2_per_km_rf*$km)/100, 2)
        );
        return $carbon;
    }


    function timezone_abbr_from_name($timezone_name){
        $dateTime = new DateTime(); 
        $dateTime->setTimeZone(new DateTimeZone($timezone_name)); 
        return $dateTime->format('T'); 
    }


    function getPNR($PNR){
        $firstTwoLines = array_slice($PNR, 0, 2);
        $finalPnr = null;
        foreach($PNR as $pnrLine)
        {
            //remove any * that appear before position 20 in a pnr
            $pnrLine = str_replace('*', " ", substr($pnrLine, 0,20)).substr($pnrLine, 20);
            //condense spaces and tabs to single space
            $pnrLine = preg_replace('/\h+/', ' ', $pnrLine);

            if (preg_match('/^RP\/\w+\/\w+\s\w+\/.+\s\w{6}$/', $pnrLine) && !$finalPnr){
                preg_match('/\w{6}$/', $pnrLine, $finalPnr);
            }
        }
        return $finalPnr;
    }

}
