<?php
// One catalogue build only. Never retained across requests or used to authorize writes.
// Permission checks (audience and blocking) stay live in their existing functions.
final class CatalogueReadBatch {
 private array $places=[],$locales=[],$hides=[],$canonical=[],$fields=[],$profiles=[];
 private ?array $contributions=null;
 private string $viewer;
 public function __construct(){
  $this->viewer=socialUser()['id']??'';
  foreach(socialQuery("SELECT p.* FROM member_places p JOIN members m ON m.id=p.owner WHERE p.state='approved' AND m.state='active'") as $r)$this->places[$r['id']]=$r;
  foreach(socialQuery("SELECT l.* FROM network_localizations l JOIN member_places p ON p.id=l.target JOIN members m ON m.id=p.owner WHERE l.kind='place' AND l.state='published' AND p.state='approved' AND m.state='active' ORDER BY l.updated,l.language") as $r)$this->locales[$r['target']][]=$r;
  foreach(socialQuery('SELECT kind,target FROM community_hides WHERE member=?',[$this->viewer]) as $r)$this->hides[$r['kind']][$r['target']]=true;
  $owners=array_unique(array_column($this->places,'owner'));
  foreach(array_chunk($owners,400) as $ids){$in=implode(',',array_fill(0,count($ids),'?'));foreach(socialQuery("SELECT m.id,m.handle,p.name,p.bio,p.avatar,p.language,p.city,p.business,COALESCE(d.mode,'name') display_mode FROM members m LEFT JOIN member_profiles p ON p.member=m.id LEFT JOIN social_display_preferences d ON d.member=m.id WHERE m.state='active' AND m.id IN ($in)",$ids) as $p){if($this->viewer===$p['id'])$p['editable_name']=$p['name'];if($p['display_mode']==='nickname')$p['name']=$p['handle'];$this->profiles[$p['id']]=$p;}}
 }
 public function places():array{return $this->places;}
 public function profile(string $id):array{return $this->profiles[$id]??[];}
 public function hidden(string $kind,string $id):bool{return isset($this->hides[$kind][$id]);}
 public function canonical(string $id):string{return $this->canonical[$id]??=cpCanonical($id);}
 public function prepareFields(array $ids):void{
  $ids=array_values(array_unique(array_map(fn($id)=>$this->canonical($id),$ids)));
  foreach(array_chunk($ids,400) as $chunk){$in=implode(',',array_fill(0,count($chunk),'?'));foreach(socialQuery("SELECT place,language,field,value FROM community_place_fields WHERE place IN ($in)",$chunk) as $r)$this->fields[$r['place']][$r['language']][]=$r;}
 }
 public function fields(string $id,string $lang,array $doc):array{
  foreach($this->fields[$this->canonical($id)][$lang]??[] as $f){$v=json_decode($f['value'],true);if($f['field']==='location'){[$doc['latitude'],$doc['longitude']]=$v;}else $doc[$f['field']]=$v;}return $doc;
 }
 public function locale(string $id,string $lang):?array{
  foreach($this->locales[$id]??[] as $r)if($r['language']===$lang){$r['document']=json_encode($this->fields('community-'.$id,$lang,json_decode($r['document'],true)),JSON_UNESCAPED_UNICODE);return $r;}
  // Match existing original-edition fallback; equal timestamps use the PK language order.
  return $this->locales[$id][0]??null;
 }
 public function editions(string $id):array{
  $rows=$this->locales[$id]??[];usort($rows,fn($a,$b)=>strcmp($a['language'],$b['language']));return $rows;
 }
 public function contributions(string $id):array{
  if($this->contributions===null){$this->contributions=[];
   foreach(socialQuery("SELECT c.*,m.handle,(SELECT COUNT(*) FROM place_contribution_votes v JOIN members vm ON vm.id=v.voter WHERE v.contribution=c.id AND v.aspect='details' AND vm.state='active') detail_votes,(SELECT COUNT(*) FROM place_contribution_votes v JOIN members vm ON vm.id=v.voter WHERE v.contribution=c.id AND v.aspect='photo' AND vm.state='active') photo_votes,(SELECT COUNT(*) FROM place_contribution_votes v JOIN members vm ON vm.id=v.voter WHERE v.contribution=c.id AND v.aspect='current' AND vm.state='active') current_votes FROM place_contributions c JOIN members m ON m.id=c.owner WHERE c.state='published' AND m.state='active' AND NOT EXISTS(SELECT 1 FROM network_edges e WHERE e.kind='block' AND ((e.actor=? AND e.target=c.owner) OR(e.target=? AND e.actor=c.owner))) ORDER BY c.created DESC,c.id DESC",[$this->viewer,$this->viewer]) as $r)if(!$this->hidden('contribution',$r['id']))$this->contributions[$this->canonical($r['place'])][]=$r;
  }return $this->contributions[$this->canonical($id)]??[];
 }
}
