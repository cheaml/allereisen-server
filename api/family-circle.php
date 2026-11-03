<?php
declare(strict_types=1);

/** Contacts are preferences, not grants. This module never calls Graph or mail. */
function family_contact(array $input): array {
    if(array_diff(array_keys($input),['name','email']))throw new InvalidArgumentException('Unbekanntes Kontaktfeld.');
    if(!is_string($input['name']??null)||!is_string($input['email']??null))throw new InvalidArgumentException('Name und E-Mail-Adresse sind erforderlich.');
    $name=trim($input['name']);$email=strtolower(trim($input['email']));
    if($name===''||mb_strlen($name)>255||preg_match('/[\x00-\x1f\x7f]/u',$name)!==0)throw new InvalidArgumentException('Bitte einen gültigen Namen mit höchstens 255 Zeichen eingeben.');
    if(strlen($email)>320||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new InvalidArgumentException('Bitte eine gültige Microsoft-E-Mail-Adresse eingeben.');
    return ['name'=>$name,'email'=>$email];
}

function family_circle_available(PDO $db): bool {
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='family_circle_contacts'")->fetchColumn();
    try{$db->query('SELECT owner_id FROM family_circle_contacts WHERE 1=0');return true;}
    catch(PDOException $e){if(in_array((string)$e->getCode(),['42S02','42P01'],true))return false;throw $e;}
}

function family_circle_list(PDO $db,int $owner): array {
    if($owner<=0)throw new InvalidArgumentException('Ungültiges Konto.');
    $q=$db->prepare('SELECT id,display_name AS name,email FROM family_circle_contacts WHERE owner_id=? ORDER BY display_name,email');
    $q->execute([$owner]);return $q->fetchAll(PDO::FETCH_ASSOC);
}

function family_circle_save(PDO $db,int $owner,?int $id,array $input): void {
    if($owner<=0||($id!==null&&$id<=0))throw new InvalidArgumentException('Ungültiger Kontakt.');
    $contact=family_contact($input);
    if($id!==null){
        $q=$db->prepare('SELECT id FROM family_circle_contacts WHERE owner_id=? AND id=?');$q->execute([$owner,$id]);
        if(!$q->fetchColumn())throw new OutOfBoundsException('Kontakt nicht gefunden.');
        $q=$db->prepare('UPDATE family_circle_contacts SET display_name=?,email=? WHERE owner_id=? AND id=?');
        $q->execute([$contact['name'],$contact['email'],$owner,$id]);
    }else{
        $q=$db->prepare('INSERT INTO family_circle_contacts(owner_id,display_name,email) VALUES(?,?,?)');
        $q->execute([$owner,$contact['name'],$contact['email']]);
    }
}

function family_circle_delete(PDO $db,int $owner,int $id): void {
    if($owner<=0||$id<=0)throw new InvalidArgumentException('Ungültiger Kontakt.');
    $q=$db->prepare('DELETE FROM family_circle_contacts WHERE owner_id=? AND id=?');$q->execute([$owner,$id]);
    if($q->rowCount()===0)throw new OutOfBoundsException('Kontakt nicht gefunden.');
}

/** Only call after verifying a device bearer credential; owner comes from it. */
function family_circle_device(PDO $db,int $owner,string $method,array $input): array {
    if(!family_circle_available($db))throw new RuntimeException('Familienkreis auf diesem Server noch nicht eingerichtet.',503);
    if($method==='GET')return ['contacts'=>family_circle_list($db,$owner)];
    if(!in_array($method,['POST','PATCH','DELETE'],true))throw new RuntimeException('Methode nicht erlaubt.',405);
    if(array_diff(array_keys($input),['id','name','email']))throw new InvalidArgumentException('Unbekanntes Kontaktfeld.');
    $id=$input['id']??null;
    if($method!=='POST'&&(!is_int($id)||$id<=0))throw new InvalidArgumentException('Ungültiger Kontakt.');
    if($method==='POST'&&$id!==null)throw new InvalidArgumentException('Neue Kontakte dürfen keine Kennung vorgeben.');
    if($method==='DELETE')family_circle_delete($db,$owner,$id);
    else family_circle_save($db,$owner,$id,['name'=>$input['name']??null,'email'=>$input['email']??null]);
    return ['contacts'=>family_circle_list($db,$owner)];
}
