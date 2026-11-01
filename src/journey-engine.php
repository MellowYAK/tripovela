<?php
declare(strict_types=1);
/** Pure deterministic evaluator. All facts are supplied by the reviewed repository. */
final class JourneyEngine {
 const VERSION='1.0';
 static function localTime(string $date,string $clock,string $zone): int {
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$clock))throw new DomainException('INVALID_TIME');
  try{$tz=new DateTimeZone($zone);}catch(Throwable $e){throw new DomainException('INVALID_TIMEZONE');}
  $wall=$date.' '.$clock;$utc=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$wall,new DateTimeZone('UTC'));
  if(!$utc||$utc->format('Y-m-d H:i')!==$wall)throw new DomainException('INVALID_DATE');
  $possible=[];$base=$utc->getTimestamp();foreach($tz->getTransitions($base-172800,$base+172800)?:[] as $tr){$n=$base-$tr['offset'];if((new DateTimeImmutable('@'.$n))->setTimezone($tz)->format('Y-m-d H:i')===$wall)$possible[$n]=true;}
  if(count($possible)!==1)throw new DomainException('AMBIGUOUS_OR_MISSING_LOCAL_TIME');return (int)array_key_first($possible);
 }
 static function evaluate(array $input,array $places,array $legs,?int $now=null): array {
  $now=$now??time();$gaps=[];$unmet=[];$warnings=['ESTIMATES_NOT_GUARANTEED'];$timeline=[];$cost=0;$remaining=0;$walk=0;$meters=0;$visits=0;$wait=0;$checkin=0;
  $out=function()use(&$unmet,&$gaps,&$warnings,&$timeline,&$cost,&$remaining,&$walk,&$meters,&$visits,&$wait,&$checkin){return ['engine_version'=>self::VERSION,'status'=>$unmet?'NO_OPTION_FOUND':($gaps?'NEEDS_DATA':'READY'),'unmet_constraints'=>array_values(array_unique($unmet)),'data_gaps'=>array_values(array_unique($gaps)),'warnings'=>$warnings,'timeline'=>$timeline,'cost_minor'=>$gaps&&in_array('REQUIRED_PRICE_UNKNOWN',$gaps,true)?null:$cost,'remaining_cash_minor'=>$remaining,'walking_minutes'=>$walk,'walking_meters'=>$meters,'visit_minutes'=>$visits,'wait_minutes'=>$wait,'checkin_minutes'=>$checkin];};
  $locale=$input['content_locale']??'';$route=$input['route']??[];$currency=$input['currency']??'';
  if(!is_string($locale)||!preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/D',$locale)||!is_array($route)||count($route)<2||count($route)>9||!preg_match('/^[A-Z]{3}$/D',$currency))throw new DomainException('INVALID_INPUT');
  $party=$input['party']??[];$adults=$party['adults']??null;$children=$party['children']??0;
  foreach([$adults,$children] as $n)if(!is_int($n)||$n<0||$n>30)throw new DomainException('INVALID_PARTY');if($adults+$children<1)throw new DomainException('INVALID_PARTY');
  foreach(['budget_minor','max_walk_meters'] as $k)if(isset($input[$k])&&(!is_int($input[$k])||$input[$k]<0))throw new DomainException('INVALID_CONSTRAINT');
  try{$start=self::localTime($input['service_date']??'',$input['start_at']??'',$input['timezone']??'');$end=self::localTime($input['finish_date']??$input['service_date'],$input['finish_by']??'',$input['timezone']);}catch(DomainException $e){$gaps[]=$e->getMessage();return $out();}
  if($end<=$start||$end-$start>86400)throw new DomainException('INVALID_WINDOW');$t=$start;$completed=$input['completed']??[];$locked=$input['locked']??[];$paid=$input['paid']??[];
  foreach(array_merge($completed,$locked,$paid) as $id)if(!isset($places[$id]))throw new DomainException('INVALID_STOP');
  foreach($locked as $id)if(!in_array($id,$route,true)&&!in_array($id,$completed,true))$unmet[]='LOCKED_ANCHOR_REMOVED';
  if($completed){if(!isset($input['current_place'],$input['current_time'])||$route[0]!==$input['current_place'])throw new DomainException('INVALID_PROGRESS');try{$t=self::localTime($input['service_date'],$input['current_time'],$input['timezone']);}catch(DomainException $e){$gaps[]=$e->getMessage();return $out();}if($t<$start||$t>$end)throw new DomainException('INVALID_PROGRESS');}
  $charged=[];$costUnknown=false;
  foreach(array_values(array_unique(array_merge($route,$completed))) as $id){
   $p=$places[$id]??null;if(!$p||($p['locale']??'')!==$locale||empty($p['published'])){$gaps[]='CONTENT_UNAVAILABLE';continue;}
   if(!empty($p['endpoint']))continue;
   $critical=['price','hours','operating'];if(!empty($input['required_step_free']))$critical[]='accessibility';
   foreach($critical as $field){$f=$p['facts'][$field]??null;if(!self::current($f,$now,$input['service_date'],$field))$gaps[]=$field==='price'?'REQUIRED_PRICE_UNKNOWN':strtoupper($field).'_UNKNOWN';}
   $op=$p['facts']['operating']??[];if(!in_array($op['value']['status']??'',['open','closed'],true))$gaps[]='OPERATING_UNKNOWN';if(($op['value']['status']??'')==='closed')$unmet[]=in_array($id,$locked,true)?'LOCKED_ANCHOR_UNAVAILABLE':'PLACE_CLOSED';
   if(!empty($input['required_step_free'])){if(($p['facts']['accessibility']['value']['step_free']??null)===false)$unmet[]='ACCESSIBILITY_UNSUITABLE';elseif(($p['facts']['accessibility']['value']['step_free']??null)!==true)$gaps[]='ACCESSIBILITY_UNKNOWN';}
   $price=$p['facts']['price']['value']??[];$unit=$price['amount_minor']??null;
   if(!is_int($unit)||$unit<0){$gaps[]='REQUIRED_PRICE_UNKNOWN';$costUnknown=true;continue;}
   if(($price['currency']??'')!==$currency){$gaps[]='CURRENCY_MISMATCH';$costUnknown=true;continue;}
   switch($price['basis']??''){
    case 'group':$amount=$unit;break;
    case 'person':$amount=$unit*($adults+$children);break;
    case 'adult':if($children&&(!isset($price['child_minor'])||!is_int($price['child_minor']))){$gaps[]='CHILD_PRICE_UNKNOWN';$costUnknown=true;continue 2;}$amount=$unit*$adults+($price['child_minor']??0)*$children;break;
    default:$gaps[]='REQUIRED_PRICE_UNKNOWN';$costUnknown=true;continue 2;
   }
   $cost+=$amount;if(!in_array($id,$paid,true))$remaining+=$amount;$charged[$id]=$amount;
  }
  if(!$costUnknown&&isset($input['budget_minor'])&&$cost>$input['budget_minor'])$unmet[]='BUDGET_EXCEEDED';
  foreach($route as $index=>$id){
   if($index===0)continue;$p=$places[$id]??null;$leg=$legs[$route[$index-1].'>'.$id]??null;
   if(!$leg||!self::current($leg,$now,$input['service_date'],'leg')){$gaps[]='WALKING_LEG_UNKNOWN';break;}
   $v=$leg['value']??[];if(($v['mode']??'')!=='walk'||!is_int($v['minutes']??null)||$v['minutes']<0||!is_int($v['meters']??null)||$v['meters']<0){$gaps[]='WALKING_LEG_UNKNOWN';break;}
   if(!empty($input['required_step_free'])){if(($v['step_free']??null)===false)$unmet[]='ACCESSIBILITY_UNSUITABLE';elseif(($v['step_free']??null)!==true)$gaps[]='ACCESSIBILITY_UNKNOWN';}
   $walk+=$v['minutes'];$meters+=$v['meters'];$t+=$v['minutes']*60;$arrival=$t;
   if(!$p)break;if(!empty($p['endpoint'])){$timeline[]=['at'=>$id,'arrive'=>$t,'depart'=>$t];continue;}
   $duration=$input['durations'][$id]??$p['visit_minutes']??null;
   if(!is_int($duration)||$duration<1||$duration>720){$gaps[]='VISIT_DURATION_UNKNOWN';break;}
   if(isset($p['facts']['duration'])&&!self::current($p['facts']['duration'],$now,$input['service_date'],'duration'))$gaps[]='VISIT_DURATION_UNKNOWN';
   if(isset($p['min_visit_minutes'])&&$duration<$p['min_visit_minutes'])$unmet[]='MINIMUM_VISIT_VIOLATED';
   $hours=$p['facts']['hours']['value']??[];$windows=$hours['dates'][$input['service_date']]??null;
   if($windows===null){$gaps[]='DATE_HOURS_UNKNOWN';break;}
   $fixed=$input['anchors'][$id]??null;$visitStart=$t;$depart=$t+$duration*60;$early=0;
   try{
    if($fixed){$deadline=self::localTime($input['service_date'],$fixed['checkin_by'],$input['timezone']);$visitStart=self::localTime($input['service_date'],$fixed['start'],$input['timezone']);$depart=self::localTime($input['service_date'],$fixed['end'],$input['timezone']);if($deadline>$visitStart||$depart<=$visitStart)throw new DomainException('INVALID_ANCHOR');if($t>$deadline)$unmet[]='CHECKIN_MISSED';$early=max(0,($visitStart-$deadline)/60);$wait+=max(0,($deadline-$t)/60);$checkin+=$early;}
    else{
     $fits=false;foreach($windows as $w){$open=self::localTime($w['date']??$input['service_date'],$w['open'],$input['timezone']);$close=self::localTime($w['close_date']??$input['service_date'],$w['close'],$input['timezone']);$last=isset($w['last_admission'])?self::localTime($w['date']??$input['service_date'],$w['last_admission'],$input['timezone']):$close;$candidate=max($t,$open);if($candidate<=$last&&$candidate+$duration*60<=$close){$visitStart=$candidate;$depart=$candidate+$duration*60;$fits=true;break;}}if(!$fits)$unmet[]='OUTSIDE_OPENING_HOURS';$wait+=max(0,($visitStart-$t)/60);
    }
    if($fixed){$fits=false;foreach($windows as $w){$open=self::localTime($w['date']??$input['service_date'],$w['open'],$input['timezone']);$close=self::localTime($w['close_date']??$input['service_date'],$w['close'],$input['timezone']);$last=isset($w['last_admission'])?self::localTime($w['date']??$input['service_date'],$w['last_admission'],$input['timezone']):$close;if($deadline>=$open&&$deadline<=$last&&$depart<=$close)$fits=true;}if(!$fits)$unmet[]='OUTSIDE_OPENING_HOURS';}
   }catch(DomainException $e){$gaps[]=$e->getMessage();break;}
   $visits+=($depart-$visitStart)/60;$buffer=$input['buffers'][$id]??0;if(!is_int($buffer)||$buffer<0||$buffer>180)throw new DomainException('INVALID_BUFFER');
   $timeline[]=['at'=>$id,'arrive'=>$arrival,'visit_start'=>$visitStart,'depart'=>$depart,'checkin_minutes'=>$early,'cost_minor'=>$charged[$id]??null,'buffer_minutes'=>$buffer];$t=$depart+$buffer*60;
  }
  if($t>$end)$unmet[]='FINISH_TIME_EXCEEDED';if(isset($input['max_walk_meters'])&&$meters>$input['max_walk_meters'])$unmet[]='WALKING_LIMIT_EXCEEDED';
  $result=$out();if($costUnknown)$result['cost_minor']=$result['remaining_cash_minor']=null;$result['finish_at']=$t;$result['elapsed_minutes']=($t-$start)/60;$result['calculated_at']=$now;return $result;
 }
 static function current(?array $fact,int $now,string $date,string $type): bool {
  if(!$fact||($fact['review_status']??'')!=='reviewed'||($fact['conflict_state']??'clear')!=='clear')return false;
  $checked=$fact['checked_at']??0;$observed=$fact['observed_at']??0;$ttl=['price'=>30,'hours'=>14,'operating'=>14,'accessibility'=>90,'leg'=>30][$type]??14;
  if(!is_int($checked)||!is_int($observed)||$checked>$now||$observed>$now||min($checked,$observed)<$now-$ttl*86400)return false;
  return ($fact['valid_from']??'9999')<=$date&&($fact['valid_until']??'0000')>=$date;
 }
 static function options(array $input,array $places,array $legs,array $alternatives,int $now): array {
  $candidates=[$input];$route=$input['route'];$locked=$input['locked']??[];$completed=$input['completed']??[];
  foreach($route as $i=>$id){if(!$i||$i===count($route)-1||in_array($id,array_merge($locked,$completed),true))continue;
   foreach(array_slice($alternatives[$id]??[],0,5) as $alt){if(in_array($alt,$route,true))continue;$n=$input;$n['route'][$i]=$alt;$candidates[]=$n;}
   if(in_array($id,$input['optional']??[],true)){$n=$input;array_splice($n['route'],$i,1);$candidates[]=$n;}
   if(isset($places[$id]['min_visit_minutes'])&&$places[$id]['min_visit_minutes']<($input['durations'][$id]??$places[$id]['visit_minutes']??0)){$n=$input;$n['durations'][$id]=$places[$id]['min_visit_minutes'];$candidates[]=$n;}
  }
  $ready=[];$seen=[];$base=self::evaluate($input,$places,$legs,$now);
  foreach(array_slice($candidates,0,40) as $candidate){$key=hash('sha256',json_encode($candidate));if(isset($seen[$key]))continue;$seen[$key]=true;$r=self::evaluate($candidate,$places,$legs,$now);if($r['status']==='READY')$ready[]=['id'=>$key,'input'=>$candidate,'result'=>$r,'diff'=>['walking_minutes'=>$r['walking_minutes']-$base['walking_minutes'],'cost_minor'=>$r['cost_minor']!==null&&$base['cost_minor']!==null?$r['cost_minor']-$base['cost_minor']:null,'finish_minutes'=>($r['finish_at']-$base['finish_at'])/60]];if(count($ready)===3)break;}
  return ['status'=>$ready?'READY':$base['status'],'options'=>$ready,'base'=>$base,'bounded_candidates'=>count($seen),'warnings'=>['BOUNDED_SEARCH_ONLY']];
 }
}
