<!doctype html>
<html>
<head>
    <title>xCoin.us &mdash; a safe and effective virtual currency</title>
    <style>
        body, input {
            font-family: Tahoma, "Lucida Grande", Ubuntu, Sans, sans-serif;
            font-size: 14px;
            color: #333;
        }
        p {
            margin: 0.5em 0;
        }
        h1 {
            font-size: 22px;
            margin-top: 0;
        }
        h2 {
            font-size: 18px;
            margin-bottom: 0;
        }
        input, textarea {
            padding: 0.25em;
        }
        input[type=submit] {
            padding: 0 0.75em;
        }
        pre {
            line-height: 2;
            font-size: 13px;
        }
        a {
            text-decoration: none;
        }
        a:hover {
            color: white;
            background: blue;
        }
    </style>
</head>
<body><?php

function coinLink($coin) {
    $link = "?account=$coin->account&coin=$coin->id";
    return '<a href="'.$link.'">Manage</a>';
}

function accountLink($account) {
    $link = "?account=$account->id";
    return '<a href="'.$link.'">'.$account->id.'</a>';
}

function b32($x) {
    $out = '';
    $map = array();
    $from = str_split('0123456789abcdef');
    $to = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $to .= $to . $to . $to . $to;
    $index = 0;
    foreach($from as $f1) {
        foreach($from as $f2) {
            $map[$f1.$f2] = $to[$index++];
        }
    }
    for($i = 0; $i < strlen($x) - 1; $i += 2) {
        $out .= $map[$x[$i] . $x[$i+1]];
    }
    return $out;
}

function newID() {
    $id = '';
    foreach(range(0, 100) as $x) {
        $id = md5($id . mt_rand()) . md5(mt_rand());
    }
    return b32($id);
}

function vpad($x) {
    return str_pad($x, 6, '0', STR_PAD_LEFT);
}

function showAccount($id) {
    header('Location: ?account='.$id);
    exit;
}

class Account {
    
    public $id;
    public $file;
    public $signature;
    public $exists = false;
    public $coins = array();
    
    public function __construct($id) {
        if(!preg_match('/^[a-z0-9]{3,16}$/', $id)) {
            throw new Exception("Invalid account id");
        }
        $this->id = $id;
        $this->file = __DIR__ . "/accounts/$id.xcoin";
        if(file_exists($this->file)) {
            foreach(file($this->file) as $spec) {
                if(strlen($spec) > 20) {
                    if($spec[0] == '@') {
                        $this->exists = true;
                        $this->signature = trim(substr($spec, 1));
                        continue;
                    }
                    $coin = Coin::parse(trim($spec));
                    $this->coins[$coin->id] = $coin;
                }
            }
        }
        $this->recalc();
    }
    
    public function setSecret($secret) {
        if(!preg_match('/^[a-z0-9]{12,256}$/', $secret)) {
            throw new Exception("Invalid secret format, must be a-z, 0-9 and 12-256 chars long");
        }
        $this->signature = $this->hashSecret($secret);
    }
    
    public function hashSecret($secret) {
        return b32(md5($secret . md5($this->id . $secret)) . md5($secret . $this->id));
    }
    
    public function verifySecret($secret) {
        if($this->signature != $this->hashSecret($secret)) {
            throw new Exception("Invalid account secret");
        }
    }
    
    public function recalc() {
        $ivalue = 0;
        $uvalue = 0;
        $ivaluev = 0;
        $uvaluev = 0;
        foreach($this->coins as $coin) {
            if($coin->deleted) {
                continue;
            }
            $ivalue += (int) $coin->ivalue;
            $uvalue += (int) $coin->uvalue;
            if($coin->verified === 'verified') {
                $ivaluev += (int) $coin->ivalue;
                $uvaluev += (int) $coin->uvalue;
            }
        }
        while($uvalue > 1e6) {
            $ivalue += 1;
            $uvalue -= 1e6;
        }
        $this->ivalue = '' . $ivalue;
        $this->uvalue = vpad($uvalue);
        while($uvaluev > 1e6) {
            $ivaluev += 1;
            $uvaluev -= 1e6;
        }
        $this->ivaluev = '' . $ivaluev;
        $this->uvaluev = vpad($uvaluev);
    }
    
    public function save() {
        $data = "@".$this->signature."\n";
        foreach($this->coins as $coin) {
            if($coin->deleted) {
                continue;
            }
            $data .= $coin->spec() . "\n";
        }
        file_put_contents($this->file, $data);
    }
    
}

class Coin {
    
    public $id;
    public $account;
    public $deleted;
    public $ivalue;     // Integer value
    public $uvalue;     // Micro units
    public $signature;  // Verification
    public $verified;
    
    public static function parse($spec) {
        list($id, $account, $ivalue, $uvalue, $signature, $verified) = explode(' ', $spec);
        return new self($id, $account, $ivalue, $uvalue, $signature, $verified);
    }
    
    public function __construct($id, $account, $ivalue, $uvalue, $signature=null, $verified=null) {
        if(is_null($id)) {
            $id = newID();
        }
        $this->deleted = false;
        $this->verified = $verified == 'verified' ? 'verified' : '--------';
        $this->id = $id;
        $this->account = $account;
        $this->ivalue = vpad($ivalue);
        $this->uvalue = vpad($uvalue);
        
        if(strlen($this->ivalue) != 6 || strlen($this->uvalue) != 6) {
            throw new Exception("Coin value is out of bounds: $this->ivalue.$this->uvalue");
        }
        
        $this->signature = $signature;
    }
    
    public function sign($secret) {
        $this->signature = $this->hashSecret($secret);
    }
    
    public function hashSecret($secret) {
        return '#' . b32(md5($this->id . $this->ivalue . $this->uvalue . $secret));
    }
    
    public function spec() {
        return "$this->id $this->account $this->ivalue $this->uvalue $this->signature $this->verified";
    }
    
    public function value() {
        return intval($this->ivalue) . '.' . $this->uvalue;
    }
}

try {
    if(isset($_POST['account'])) {
        $id = $_POST['account'];
        $account = new Account($id);
        
        /**
         * Create Account
         */
        if(isset($_POST['create_secret'])) {
            if($account->exists) {
                throw new Exception("Account $id exists");
            }
            $account->setSecret($_POST['create_secret']);
            $account->save();
            showAccount($id);
        }
        
        /**
         * Claim Bounty
         */
        if(isset($_POST['bounty_amount'])) {
            if(!$account->exists) {
                throw new Exception("Account $id does not exist");
            }
            $secret = $_POST['secret'];
            $account->verifySecret($secret);
            $amount = $_POST['bounty_amount'];
            $tmp = explode('.', $amount);
            if(!isset($tmp[1])) {
                $tmp[] = '0';
            }
            $ivalue = intval(array_shift($tmp));
            $uvalue = substr(array_shift($tmp), 0, 6);
            
            $uvalue = intval(str_pad($uvalue, 6, '0'));
            $coin = new Coin(null, $id, $ivalue, $uvalue);
            $coin->sign($secret);
            $account->coins[$coin->id] = $coin;
            $account->save();
            showAccount($id);
        }
    }
    if(isset($_GET['account'])) {
        $account = new Account($_GET['account']);
        
        if(isset($_GET['coin'])) { 
        
            $coin = $account->coins[$_GET['coin']]; ?>
        
            <h1>xCoin Manage Coin</h1>
            <p>Account: <?php echo accountLink($account); ?></p>
            <p>Account Balance: <?php echo $account->ivalue.".".$account->uvalue; ?>xc</p>
            <p>Verified Balance: <?php echo $account->ivaluev.".".$account->uvaluev; ?>xc</p>
            <p>Coin Value: <?php echo $coin->value(); ?>xc</p>
            <p>ID: <?php echo $coin->id; ?></p>
            
            <h2>Verify</h2>
            <p>&bull; You must verify coins before spending them.</p>
            <form action="?" method="POST">
                <input type="text" name="verify_coin" value="<?php echo $coin->id; ?>" placeholder="Coin ID" />
                <input type="text" name="account" value="<?php echo $account->id; ?>" placeholder="From Account ID" />
                <input type="text" name="secret" placeholder="From Account Secret" />
                <input type="submit" value="Verify Coin"</a>
            </form>
            
            <h2>Regenerate</h2>
            <p>&bull; You must regenerate coins you recieve from others for safety.</p>
            <form action="?" method="POST">
                <input type="text" name="regenerate_coin" value="<?php echo $coin->id; ?>" placeholder="Coin ID" />
                <input type="text" name="account" value="<?php echo $account->id; ?>" placeholder="From Account ID" />
                <input type="text" name="secret" placeholder="From Account Secret" />
                <input type="submit" value="Regenerate Coin"</a>
            </form>
            
            <h2>Spend this coin: <?php echo $coin->value(); ?>xc</h2>
            <p>&bull; This takes place immediately, but the reciever must verify and regenerate the coins.</p>
            <form action="?" method="POST">
                <input type="text" name="coin" value="<?php echo $coin->id; ?>" placeholder="Coin ID" />
                <input type="text" name="account" value="<?php echo $account->id; ?>" placeholder="From Account ID" />
                <input type="text" name="to_account" value="" placeholder="To Account ID" />
                <input type="text" name="secret" placeholder="From Account Secret" />
                <input type="submit" value="Spend Coin"</a>
            </form>
            
            <h2>Split this coin into two coins</h2>
            <p>&bull; You must verify the coins once split.</p>
            <form action="?" method="POST">
                <input type="text" name="coin" value="<?php echo $coin->id; ?>" placeholder="Coin ID" />
                <input type="text" name="account" value="<?php echo $account->id; ?>" placeholder="From Account ID" />
                <input type="text" name="split_amount" value="" placeholder="Split Amount" />
                <input type="text" name="secret" placeholder="Account Secret" />
                <input type="submit" value="Split Coin"</a>
            </form>
            
            <h2>Merge this coin with another coin</h2>
            <p>&bull; You must verify the coin once merged.</p>
            <form action="?" method="POST">
                <input type="text" name="coin" value="<?php echo $coin->id; ?>" placeholder="Coin ID" />
                <input type="text" name="account" value="<?php echo $account->id; ?>" placeholder="From Account ID" />
                <input type="text" name="merge_coin" value="" placeholder="Merge Coin ID" />
                <input type="text" name="secret" placeholder="Account Secret" />
                <input type="submit" value="Merge Coin"</a>
            </form>
        
        <?php } else { ?>
        
            <h1>xCoin Account Lookup</h1>
            <p>Account: <?php echo accountLink($account); ?></p>
            <p>Account Balance: <?php echo $account->ivalue.".".$account->uvalue; ?>xc</p>
            <p>Verified Balance: <?php echo $account->ivaluev.".".$account->uvaluev; ?>xc</p>
            <p>Coins:</p>
            <pre><?php $hasCoins = false;
            foreach($account->coins as $coin) {
                if($coin->deleted) {
                    continue;
                }
                $hasCoins = true;
                echo coinLink($coin) . ' ' . $coin->spec() . "\n";
            }
            if(!$hasCoins) {
                echo "<i> &ndash; none &ndash; </i>";
            }
            ?></pre>
            <?php if($account->exists) { ?>
                <h2>Claim xCoin Bounty</h2>
                <p>&bull; On the 1st of every month, request account bounty. Verify the next day.</p>
                <form action="?" method="POST">
                    <input type="text" name="account" value="<?php echo $account->id; ?>" placeholder="Account ID" />
                    <input type="text" name="bounty_amount" value="100.000000" placeholder="Amount" />
                    <input type="text" name="secret" placeholder="Account Secret" />
                    <input type="submit" value="Claim Bounty"</a>
                </form>
            <?php } else { ?>
                <h2>Create xCoin Account</h2>
                <form action="?" method="POST">
                    <input type="text" name="account" value="<?php echo $account->id; ?>" placeholder="Account ID" />
                    <input type="text" name="create_secret" placeholder="Account Secret" />
                    <input type="submit" value="Create Account"</a>
                </form>
            <?php } ?>
        <?php } ?>
    <?php } else { ?>
        <h1>xCoin Account Search</h1>
        <p>Enter account ID:</p>
        <form action="?">
            <input name="account" type="text" />
            <input type="submit" />
        </form>
    <?php }
} catch(Exception $e) {
    echo "<pre>";
    echo $e->getMessage();
    echo "</pre>";
}

?></body>
</html>