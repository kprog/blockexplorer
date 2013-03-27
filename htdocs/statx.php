<?php

//senderror(503);
//header("Content-type: text/plain");
//echo "ERROR: BBE /q pages are temporarily disabled";
//die();

if($page!="mytransactions")
{
	header("Cache-control: no-cache");
}
require_once 'util.php';
require_once 'jsonrpc.php';

function cacheput($key,$data,$secs)
{
	$shm="/dev/shm/bbe/";
	if(!file_exists($shm))
	{
		if(!mkdir($shm))
			return false;
		chmod($shm,0775);
	}
	
	$length=strlen($data);
	$chk=crc32($data);
	$expires=time()+$secs;
	$header="$expires;$length;$chk";
	$data=$header."\n\n".$data;
	
	$file=fopen($shm.$key,"c");
	if($file===false)
		return false;
	if(!flock($file,LOCK_EX|LOCK_NB))
		return false;
	ftruncate($file,0);
	fwrite($file,$data);
	flock($file,LOCK_UN);
	fclose($file);
}

function cacheget($key)
{
	$shm="/dev/shm/bbe/";
	if(!file_exists($shm))
	{
		if(!mkdir($shm))
			return false;
		chmod($shm,0775);
	}
	
	if(!file_exists($shm.$key))
	{
		return false;
	}
	
	$file=@fopen($shm.$key,"r");
	if($file===false)
		return false;
	if(!flock($file,LOCK_SH))
		return false;
	$header=explode(";",fgets($file));
	$time=$header[0];
	$length=$header[1];
	$chk=$header[2];
	if(empty($time)||empty($length)||empty($chk))
		return false;
	
	if($time<time())
	{
		flock($file,LOCK_UN);
		fclose($file);
		return false; // we'll put new stuff in it later
		/*flock($file,LOCK_UN);
		fclose($file);
		$file=fopen($shm.$key,"c");
		if($file===false)
			return false;
		flock($file,LOCK_EX);
		@unlink($shm.$key);
		flock($file,LOCK_UN);
		fclose($file);
		return false;*/
	}
	
	fgets($file); //advance pointer
	$data=fread($file,$length);
	if(!empty($data)&&strlen($data)==$length&&crc32($data)==$chk)
	{
		return $data;
	}
	else
	{
		error_log("Bad cache file: $key, length $length");
		flock($file,LOCK_UN);
		fclose($file);
		return false;
		/*flock($file,LOCK_UN);
		fclose($file);
		$file=fopen($shm.$key,"c");
		flock($file,LOCK_EX);
		unlink($shm.$key);
		flock($file,LOCK_UN);
		fclose($file);
		return false;*/
	}
}

function decodeCompact($c)
{
	$nbytes = ($c >> 24) & 0xFF;
	return bcmul($c & 0xFFFFFF,bcpow(2,8 * ($nbytes - 3)));
}
function encodeCompact($in)
{
	return exec("/var/www/blockexplorer.com/bin/getcompact.py $in");

	//By ArtForz
	/*
	#!/usr/bin/python
import struct
import sys

def num2mpi(n):
        """convert number to MPI string"""
        if n == 0:
                return struct.pack(">I", 0)
        r = ""
        neg_flag = bool(n < 0)
        n = abs(n)
        while n:
                r = chr(n & 0xFF) + r
                n >>= 8
        if ord(r[0]) & 0x80:
                r = chr(0) + r
        if neg_flag:
                r = chr(ord(r[0]) | 0x80) + r[1:]
        datasize = len(r)
        return struct.pack(">I", datasize) + r

def GetCompact(n):
        """convert number to bc compact uint"""
        mpi = num2mpi(n)
		        nSize = len(mpi) - 4
        nCompact = (nSize & 0xFF) << 24
        if nSize >= 1:
                nCompact |= (ord(mpi[4]) << 16)
        if nSize >= 2:
                nCompact |= (ord(mpi[5]) << 8)
        if nSize >= 3:
                nCompact |= (ord(mpi[6]) << 0)
        return nCompact

print GetCompact(eval(sys.argv[1]))
	*/
}

function dbconnect()
{
	$db=@pg_connect("dbname=explore connect_timeout=2");
	pg_query("set statement_timeout to 60000;");
	if(!$db)
	{
		senderror(503);
		//echo "ERROR: Could not connect to database. Try again in a few minutes. Tell me if it still doesn't work in 30 minutes.";
		echo "ERROR: Database timeout (likely overload). Try again later.";
		//error_log("/q/ database down");
		die();
	}
	return $db;
}
function dbquery($db,$query,$params=false)
{
	if($params!==false)
	{
		$return=pg_query_params($db,$query,$params);
	}
	else
	{
		$return=pg_query($db,$query);
	}
	if(!$return)
	{
		senderror(500);
		echo "ERROR: Database problem. Try again in a few minutes. Tell me if it still doesn't work in 30 minutes.";
		error_log("/q/ invalid pg_query");
		die();
	}
	return $return;
	
}
$getblockcountcache=0;
function getblockcount()
{
	global $getblockcountcache;
	if($getblockcountcache==0)
	{
		$cache=cacheget("getblockcount");
		if($cache!==false)
			return (integer)$cache;
		
		$data=rpcQuery("getblockcount");
		if(!isset($data)||is_null($data)||is_null($data["r"])||!is_null($data["e"])||!is_int($data["r"]))
		{
			senderror(503);
			echo "ERROR: Could not connect to JSON-RPC. Try again in a few minutes. Tell me if it still doesn't work in 30 minutes.";
			error_log("/q/ getblockcount failure: {$data["e"]}");
			die();
		}
		$getblockcountcache=$data["r"];
		
		cacheput("getblockcount",$data["r"],5);
		return $data["r"];
	}
	else
	{
		return $getblockcountcache;
	}
}

function getdifficulty()
{
	$cache=cacheget("getdifficulty");
	if($cache!==false)
		return $cache;
		
	$data=rpcQuery("getdifficulty");
	if(!isset($data)||is_null($data)||is_null($data["r"])||!is_null($data["e"])||!is_float($data["r"]))
	{
		senderror(503);
		echo "ERROR: Could not connect to JSON-RPC. Try again in a few minutes. Tell me if it still doesn't work in 30 minutes.";
		error_log("/q/ getdifficulty failure: {$data["e"]}");
		die();
	}
	cacheput("getdifficulty",$data["r"],20);
	return $data["r"];
}
function getblockbynumber($num)
{
	$cache=cacheget("getblock$num");
	if($cache!==false)
		return unserialize($cache);
	
	$data=rpcQuery("getblock",array($num));
	if(!isset($data)||is_null($data)||is_null($data["r"])||!is_null($data["e"]))
	{
		senderror(503);
		echo "ERROR: Could not connect to JSON-RPC. Try again in a few minutes. Tell me if it still doesn't work in 30 minutes.";
		error_log("/q/ getblockbynumber failure: {$data["e"]}");
		die();
	}
	
	cacheput("getblock$num",serialize($data["r"]),10);
	return $data["r"];
}

function getdecimaltarget()
{
	$dtblock=getblockbynumber(getblockcount());
	$target=$dtblock->bits;
	return decodeCompact($target);	
}
function getprobability()
{
	return bcdiv(getdecimaltarget(),"115792089237316195423570985008687907853269984665640564039457584007913129639935",55);
}
function getlastretarget()
{
	$blockcount=getblockcount();
	return ($blockcount-($blockcount%2016))-1;
}
if($page=="home")
{
echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN"
"http://www.w3.org/TR/html4/strict.dtd">
<html>
<head>
<title>Bitcoin real-time stats and tools</title>
</head>
<body>
<p>Usage: /q/query[/parameter]</p>
<p>Queries currently supported:</p>
<h4>Real-time stats</h4>
<p>While <a href="/">Bitcoin Block Explorer</a> can run at a delay of up to two minutes, these tools are all completely real-time.</p>
<ul>
<li><a href="/q/getdifficulty">getdifficulty</a> - shows the current difficulty as a multiple of the minimum difficulty (highest target).</li>
<li><a href="/q/getblockcount">getblockcount</a> - shows the number of blocks in the longest block chain (not including the genesis block). Equivalent to Bitcoin\'s getblockcount.</li>
<li><a href="/q/latesthash">latesthash</a> - shows the latest block hash.</li>
<li><a href="/q/getblockhash">getblockhash</a> - returns the hash of a block at a given height.</li>
<li><a href="/q/hextarget">hextarget</a> - shows the current target as a hexadecimal number.</li>
<li><a href="/q/decimaltarget">decimaltarget</a> - shows the current target as a decimal number.</li>
<li><a href="/q/probability">probability</a> - shows the probability of a single hash solving a block with the current difficulty.</li>
<li><a href="/q/hashestowin">hashestowin</a> - shows the average number of hashes required to win a block with the current difficulty.</li>
<li><a href="/q/nextretarget">nextretarget</a> - shows the block count when the next retarget will take place.</li>
<li><a href="/q/estimate">estimate</a> - shows an estimate for the next difficulty.</li>
<li><a href="/q/totalbc">totalbc</a> - shows the total number of Bitcoins in circulation. You can also <a href="/q/totalbc/50000">see the circulation at a particular number of blocks</a>.</li>
<li><a href="/q/bcperblock">bcperblock</a> - shows the number of Bitcoins created per block. You can also <a href="/q/bcperblock/300000">see the BC per block at a particular number of blocks.</a></li>
</ul>
<h4>Delayed stats</h4>
<p>These use BBE data.</p>
<ul>
<li><a href="/q/avgtxsize">avgtxsize</a> - shows the average transaction data size in bytes. The parameter sets how many blocks to look back at (default 1000).</li>
<li><a href="/q/avgtxvalue">avgtxvalue</a> - shows the average BTC input value per transaction, not counting generations. The parameter sets how many blocks to look back at (default 1000).</li>
<li><a href="/q/avgblocksize">avgblocksize</a> - shows the average block size. The parameter sets how many blocks to look back at (default 1000).</li>
<li><a href="/q/interval">interval</a> - shows the average interval between blocks, in seconds. The parameter sets how many blocks to look back at (default 1000).</li>
<li><a href="/q/eta">eta</a> - shows the estimated number of seconds until the next retarget. The parameter sets how many blocks to look back at (default 1000). Blocks before the last retarget are never taken into account, however.</li>
<li><a href="/q/avgtxnumber">avgtxnumber</a> - shows the average number of transactions per block. The parameter sets how many blocks to look back at (default 1000).</li>
<li><a href="/q/getreceivedbyaddress">getreceivedbyaddress</a> - shows the total BTC received by an address.</li>
<li><a href="/q/getsentbyaddress">getsentbyaddress</a> - shows the total BTC sent by an address. <i>Do not use this unless you know what you are doing: it does not do what you might expect.</i></li>
<li><a href="/q/addressbalance">addressbalance</a> - shows received BTC minus sent BTC for an address. <i>Do not use this unless you know what you are doing: it does not do what you might expect.</i></li>
<li><a href="/q/addressfirstseen">addressfirstseen</a> - shows the time at which an address was first seen on the network.</li>
<li><a href="/q/nethash">nethash</a> - produces CSV statistics about block difficulty. The parameter sets the interval between data points.</li>
<li><a href="/q/mytransactions">mytransactions</a> - dumps all transactions for given addresses</li>
<li><a href="/q/reorglog">reorglog</a> - a log of reorg events</li>
</ul>

<h4>Tools</h4>
<ul>
<li><a href="/q/addresstohash">addresstohash</a> - converts a Bitcoin address to a hash160.</li>
<li><a href="/q/hashtoaddress">hashtoaddress</a> - converts a hash160 to a Bitcoin address.</li>
<li><a href="/q/checkaddress">checkaddress</a> - checks a Bitcoin address for validity.</li>
<li><a href="/q/hashpubkey">hashpubkey</a> - creates a hash160 from a public key.</li>
<li><a href="/q/changeparams">changeparams</a> - calculates the end total number of bitcoins with different starting parameters.</li>
</ul>

<p>This server is up more than 99% of the time, but anything that pulls data from here should still be prepared for failure.</p>
</body>
</html>
';
die();
}
else
{
	header("Content-type: text/plain");
}
//start main block - anything before this must die()
if($page=="getdifficulty")
{
	echo getdifficulty();
}
else if($page=="getblockcount")
{
	echo getblockcount();
}
else if($page=="latesthash")
{
	$block=getblockbynumber(getblockcount());
	echo strtoupper($block->hash);
}
else if($page=="getblockhash")
{
	if(isset($param1)&&preg_match('/^[0-9]{1,9}$/',$param1)==1)
	{
		$block=(int)$param1;
		if($block<=(int)getblockcount())
		{
			$block=getblockbynumber((int)$param1);
			echo strtoupper($block->hash);
		}
		else
		{
			senderror(404);
			echo "ERROR: block not found";
			die();
		}
	}
	else
	{
		echo "Returns the hash of a block at a given height.\n\n/q/getblockhash/hash";
	}
}
else if($page=="hextarget")
{
	$target=encodeHex(getdecimaltarget());
	while(strlen($target)<64)
	{
		$target="0".$target;
	}
	echo $target;
}
else if($page=="decimaltarget")
{
	echo getdecimaltarget();
}
else if($page=="probability")
{
	echo getprobability();
}
else if($page=="hashestowin")
{
	echo bcdiv("1",getprobability(),0);
}
else if($page=="nextretarget")
{
	echo getlastretarget()+2016;
}
else if($page=="estimate")
{
	$currentcount=getblockcount(); //last one with the old difficulty
	$last=getlastretarget()+1; //first one with the "new" difficulty
	$targettime=600*($currentcount-$last+1);
	//check for cases where we're comparing the same two blocks
	if($targettime==0)
	{
		echo getdifficulty();
		die();
	}
	
	$oldblock=getblockbynumber($last);
	$newblock=getblockbynumber($currentcount);
	$oldtime=$oldblock->time;
	$oldtarget=decodeCompact($oldblock->bits);
	$newtime=$newblock->time;
	
	$actualtime=$newtime-$oldtime;
	
	if($actualtime<$targettime/4)
	{
		$actualtime=$targettime/4;
	}
	if($actualtime>$targettime*4)
	{
		$actualtime=$targettime*4;
	}
	
	$newtarget=bcmul($oldtarget,$actualtime);
	//check once more for safety
	if($newtarget=="0")
	{
		echo getdifficulty();
		die();
	}
	$newtarget=bcdiv($newtarget,$targettime,0);
	$newtarget=decodeCompact(encodeCompact($newtarget));
	//we now have the real new target
	echo bcdiv("26959535291011309493156476344723991336010898738574164086137773096960",$newtarget,8);
}
else if($page=="totalbc"||$page=="bcperblock")
{
	if(isset($param1)&&preg_match('/^[0-9]+$/',$param1)==1)
	{
		$blockcount=(string)$param1;
		if($blockcount>6929999)
		{
			$blockcount="6930000";
		}
	}
	else
	{
		$blockcount=getblockcount();
	}
	$blockworth="50";
	//for genesis block
	$totalbc="50";
	bcscale(8);
	//$blockcount++; //genesis block
	while(bccomp($blockcount,"0")==1) //while blockcount is larger than 0
	{
		if(bccomp($blockcount,"210000")==-1) //if blockcount is less than 210000
		{
			$totalbc=(string)bcadd($totalbc,bcmul($blockworth,$blockcount));
			$blockcount="0";
		}
		else
		{
			$blockcount=bcsub($blockcount,"210000");
			$totalbc=(string)bcadd($totalbc,bcmul($blockworth,"210000"));
			$blockworth=bcdiv($blockworth,"2",8);
		}
	}
	
	if($page=="totalbc")
	{
		echo $totalbc;
	}
	else
	{
		echo $blockworth;
	}
}

else if($page=="changeparams")
{
	$blockcount="10000000000";
	$origblockcount=$blockcount;
	$didsomething=0;
	if(isset($_GET['subsidy']))
	{
		$blockworth=(string)$_GET['subsidy'];
		$didsomething=1;
	}
	else
	{
		$blockworth=50;
	}
	if(isset($_GET['precision']))
	{
		$precision=(integer)$_GET['precision'];
		if($precision>9000)
		{
		echo "Precision level over nine thousand! (Don't kill my server.)";
		die();
		}
		$didsomething=1;
	}
	else
	{
		$precision=8;
	}
	if(isset($_GET['interval']))
	{
		$interval=(string)$_GET['interval'];
		$didsomething=1;
	}
	else
	{
		$interval="210000";
	}
	if($didsomething!=1)
	{
		echo "This gives you the end total BC and the time required to reach it after changing various parameters. \nSubsidy - Starting subsidy (generation reward)\nInterval - Subsidy is halved after this many blocks\nPrecision - Decimals of precision\nLeave a parameter out to use the Bitcoin default:\n";
		echo "/q/changeparams?interval=210000&precision=8&subsidy=50";
		die();
	}
	$totalbc="0";
	bcscale($precision);
	while(bccomp($blockcount,"0")==1&&$blockworth!="0") //while blockcount is larger than 0
	{
		if(bccomp($blockcount,$interval)==-1) //if blockcount is less than 210000
		{
			$totalbc=(string)bcadd($totalbc,bcmul($blockworth,$blockcount));
			$blockcount="0";
			if($blockworth!=0)
			{
				echo "Could not complete calculation in 10,000,000,000 blocks. (This is an arbitrary limit of the calculator.)";
				die();
			}
		}
		else
		{
			$blockcount=bcsub($blockcount,$interval);
			$totalbc=(string)bcadd($totalbc,bcmul($blockworth,$interval));
			$blockworth=bcdiv($blockworth,"2");
		}
	}
	echo "Final BC in circulation: ".$totalbc;
	echo "\n";
	$realchange=bcsub($origblockcount,$blockcount,0);
	echo "Took ".$realchange." blocks (".bcdiv($realchange,"52560",3)." years).";
}

else if($page=="addresstohash")
{
	if(isset($param1))
	{
		$address=trim($param1);
		if(preg_match('/^[1-9A-HJ-NP-Za-km-z]+$/',$address)==1 &&strlen($address)<300)
		{
			echo addressToHash160($address);
		}
		else
		{
			senderror(400);
			echo "ERROR: the input is not base58 (or is too large).";
		}
	}
	else
	{
		echo "Converts a BC address to the hash160 format used internally by Bitcoin.\nNote: the address is not checked for validity.\n/q/addresstohash/address";
	}
}

else if($page=="hashtoaddress")
{

	$addressversion=ADDRESSVERSION;
	if(isset($param2))
	{
		$addressversion=strtoupper(remove0x(trim((string)$param2)));
		if(strlen($addressversion)!=2||preg_match('/^[0-9A-F]+$/',$addressversion)!=1)
		{
			echo "ERROR: AddressVersion is a two-character hexadecimal byte. Like 1F or 03. Using default.\n";
			$addressversion=ADDRESSVERSION;
		}
	}
	if(isset($param1))
	{
		$hash160=strtoupper(remove0x(trim($param1)));
		if(preg_match('/^[0-9A-F]+$/',$hash160)==1 && strlen($hash160)<400)
		{
			if(strlen($hash160)%2==0)
			{
				echo hash160ToAddress($hash160,$addressversion);
			}
			else
			{
				senderror(400);
				echo "ERROR: it doesn't make sense to have an uneven number of hex characters.
(Perhaps you can add or remove some leading zeros.)";
			}
		}
		else
		{
			senderror(400);
			echo "ERROR: the input is not hex (or is too large).";
		}
	}
	else
	{
		echo "Converts a Bitcoin hash160 (in hex) to a valid BC address.\n/q/hashtoaddress/hexHash[/AddressVersion]";
	}
}

else if($page=="checkaddress")
{
	if(isset($param1))
	{
		$address=trim($param1);
		if(preg_match('/^[1-9A-HJ-NP-Za-km-z]+$/',$address)==1)
		{
			if(strlen($address)<300)
			{
				$address=decodeBase58($address);
				if(strlen($address)==50)
				{
					$version=substr($address,0,2);
					$check=substr($address,0,strlen($address)-8);
					$check=pack("H*" , $check);
					$check=strtoupper(hash("sha256",hash("sha256",$check,true)));
					$check=substr($check,0,8);
					if($check==substr($address,strlen($address)-8))
					{
						echo $version;
					}
					else
					{
						echo "CK";
					}
				}
				else
				{
					echo "SZ";
				}
			}
			else
			{
				echo "SZ";
			}
		}
		else
		{
			echo "X5";
		}
	}
	else
	{
	echo "Returns 00 if the address is valid, something else otherwise. Note that it
is impossible to determine whether someone actually *owns* the address. Someone
could easily give 20 random bytes to /q/hashtoaddress and get a valid address.
X5 - Address not base58
SZ - Address not the correct size
CK - Failed hash check
Anything else - the encoded AddressVersion (always 00 in valid addresses)

/q/checkaddress/address";

	}
}

else if($page=="hashpubkey")
{
	if(isset($param1))
	{
		$pubkey=strtoupper(remove0x(trim($param1)));
		if(preg_match('/^[0-9A-F]+$/',$pubkey)==1 && strlen($pubkey)<300)
		{
			echo hash160($pubkey);
		}
		else
		{
			senderror(400);
			echo "ERROR: the input is not hex (or is too large).";
		}
	}
	else
	{
		echo "Generates a hash160 from a Bitcoin public key. In the current implementation,
public keys are either the first 65 bytes (130 hex characters) of a scriptPubKey
or the last 65 bytes of a scriptSig, depending on the type of transaction. They
always seem to start with 04 (this must be included).

/q/hashpubkey/hexPubKey";
	}
}

else if($page=="avgtxsize")
{
	if(!isset($param1))
	{
		$param1=1000;
	}
	$param1=(int)$param1;
	if($param1>0)
	{
		$db=dbconnect();
		$result=dbquery($db,"SELECT round(avg(transactions.size),0) AS avg FROM transactions JOIN blocks ON (transactions.block=blocks.hash) WHERE blocks.number>(SELECT max(number) FROM blocks)-$1;",array($param1));
		$result=pg_fetch_assoc($result);
		$result=$result["avg"];
		echo $result;
	}
	else
	{
		senderror(400);
		echo "ERROR: the first parameter is the number of blocks to look back through.";
	}
}

else if($page=="avgtxvalue")
{
	if(!isset($param1))
	{
		$param1=1000;
	}
	$param1=(int)$param1;
	if($param1>0)
	{
		$db=dbconnect();
		$result=dbquery($db,"SELECT coalesce(round(avg(sum),8),'0') AS avg FROM (SELECT sum(inputs.value),inputs.tx AS avg FROM inputs JOIN blocks ON (inputs.block=blocks.hash) WHERE blocks.number>(SELECT max(number) FROM blocks)-$1 AND inputs.type<>'Generation' GROUP BY inputs.tx) AS a;",array($param1));
		$result=pg_fetch_assoc($result);
		$result=$result["avg"];
		echo $result;
	}
	else
	{
		senderror(400);
		echo "ERROR: the first parameter is the number of blocks to look back through.";
	}
}

else if($page=="avgblocksize")
{
	if(!isset($param1))
	{
		$param1=1000;
	}
	$param1=(int)$param1;
	if($param1>0)
	{
		$db=dbconnect();
		$result=dbquery($db,"SELECT round(avg(size),0) AS avg FROM blocks WHERE blocks.number>(SELECT max(number) FROM blocks)-$1;",array($param1));
		$result=pg_fetch_assoc($result);
		$result=$result['avg'];
		echo $result;
	}
	else
	{
		senderror(400);
		echo "ERROR: the first parameter is the number of blocks to look back through.";
	}
}

else if($page=="interval")
{
	if(!isset($param1))
	{
		//default lookback
		$param1=1000;
	}
	$param1=(int)$param1;
	if($param1<2)
	{
		senderror(400);
		echo "ERROR: invalid block count.";
		die();
	}
	
	$cache=cacheget("interval$param1");
	if($cache!==false)
	{
		echo (integer)$cache;
		die();
	}
	
	$db=dbconnect();
	$result=pg_fetch_assoc(dbquery($db,"SELECT round((EXTRACT ('epoch' FROM avg(time.time)))::numeric,0) AS avg FROM (SELECT time-lag(time,1) OVER (ORDER BY time) AS time FROM blocks WHERE blocks.number>(SELECT max(number)-$1 FROM blocks)) AS time;",array($param1)));
	$result=$result['avg'];
	
	cacheput("interval$param1",$result,30);
	
	echo $result;
}

else if($page=="eta")
{
	if(!isset($param1))
	{
		//default lookback
		$param1=1000;
	}
	$param1=(int)$param1;
	if($param1<2)
	{
		senderror(400);
		echo "ERROR: invalid block count.";
		die();
	}
	$param1=min(getblockcount()-getlastretarget(),$param1);
	$param1=max($param1,2);
	$db=dbconnect();
	$result=pg_fetch_assoc(dbquery($db,"SELECT round((EXTRACT ('epoch' FROM avg(time.time)))::numeric,0) AS avg FROM (SELECT time-lag(time,1) OVER (ORDER BY time) AS time FROM blocks WHERE blocks.number>(SELECT max(number)-$1 FROM blocks)) AS time;",array($param1)));
	$result=$result['avg'];
	$blocksleft=(getlastretarget()+2016)-getblockcount();
	if($blocksleft==0)
	{
		$blocksleft=2016;
	}
	echo $blocksleft*$result;	
}

else if($page=="avgtxnumber")
{
	if(!isset($param1))
	{
		//default lookback
		$param1=1000;
	}
	$param1=(int)$param1;
	if($param1<1)
	{
		senderror(400);
		echo "ERROR: invalid block count.";
		die();
	}
	$db=dbconnect();
	$result=pg_fetch_assoc(dbquery($db,"SELECT round(avg(a.count),3) AS avg FROM (SELECT block,count(*) AS count FROM transactions GROUP BY block) AS a JOIN blocks ON blocks.hash=a.block WHERE blocks.number>(SELECT max(number)-$1 FROM blocks);",array($param1)));
	$result=$result['avg'];
	echo $result;
}

else if($page=="getreceivedbyaddress")
{
	if(isset($param1))
	{
		$param1=trim($param1);
	}
	else
	{
		echo "Returns total BTC received by an address. Sends are not taken into account.\nThe optional second parameter specifies the required number of confirmations for\ntransactions comprising the balance.\n/q/getreceivedbyaddress/address[/minconf]";
		die();
	}
	
	$minconf=1;
	if(isset($param2))
	{
		$param2=(int)$param2;
		if(empty($param2)||!is_int($param2)||$param2<0)
		{
			senderror(400);
			echo "ERROR: you must use an integer above 0 for minconf";
			die();
		}
		if($param2==0) //I don't think this can happen currently
		{
			senderror(400); 
			echo "ERROR: this page never counts 0-confirmation transactions";
			die();
		}
		$minconf=(int)$param2;
	}
	
	if(isset($param1)&&strlen($param1)>24&&strlen($param1)<36&&checkAddress($param1))
	{
		$hash160=addressToHash160($param1);
		$db=dbconnect();
		$result=pg_fetch_assoc(dbquery($db,"SELECT sum(value) AS sum FROM outputs WHERE hash160=decode($1,'hex') AND block NOT IN (SELECT hash FROM blocks ORDER BY number DESC LIMIT $2);",array($hash160,$minconf-1)));
		$result=$result["sum"];
		if(is_null($result))
		{
			$result=0;
		}
		echo $result;
		
	}
	else
	{
		senderror(400);
		echo "ERROR: invalid address";
	}
}
else if($page=="getsentbyaddress")
{
	if(isset($param1))
	{
		$param1=trim($param1);
	}
	else
	{
		echo "Returns total BTC sent by an address. Using this data is almost always a very\nbad idea, as the amount of BTC sent by an address is usually very different\nfrom the amount of BTC sent by the person owning the address.\n/q/getsentbyaddress/address";
		die();
	}
	
	if(isset($param1)&&strlen($param1)>24&&strlen($param1)<36&&checkAddress($param1))
	{
		$hash160=addressToHash160($param1);
		$db=dbconnect();
		$result=pg_fetch_assoc(dbquery($db,"SELECT sum(value) AS sum FROM inputs WHERE hash160=decode($1,'hex');",array($hash160)));
		$result=$result["sum"];
		if(is_null($result))
		{
			$result=0;
		}
		echo $result;
		
	}
	else
	{
		senderror(400);
		echo "ERROR: invalid address";
	}
}

else if($page=="addressbalance")
{
	if(isset($param1))
	{
		$param1=trim($param1);
	}
	else
	{
		echo "This is the same as subtracting /q/getsentbyaddress from /q/getreceivedbyaddress.\nUsing this data is almost always a very bad idea, as the amount of BTC sent\nby an address is usually very different from the amount of BTC sent by the\nperson owning the address.\n/q/addressbalance/address";
		die();
	}
	
	if(isset($param1)&&strlen($param1)>24&&strlen($param1)<36&&checkAddress($param1))
	{
		$hash160=addressToHash160($param1);
		$db=dbconnect();
		$sent=pg_fetch_assoc(dbquery($db,"SELECT sum(value) AS sum FROM inputs WHERE hash160=decode($1,'hex');",array($hash160)));
		$sent=$sent["sum"];
		if(is_null($sent))
		{
			$sent=0;
		}
		
		$received=pg_fetch_assoc(dbquery($db,"SELECT sum(value) AS sum FROM outputs WHERE hash160=decode($1,'hex');",array($hash160)));
		$received=$received["sum"];
		if(is_null($received))
		{
			$received=0;
		}
		echo $received-$sent;
		
	}
	else
	{
		senderror(400);
		echo "ERROR: invalid address";
	}
}

else if($page=="addressfirstseen")
{
	if(isset($param1))
	{
		$param1=trim($param1);
	}
	else
	{
		echo "Returns the block time at which an address was first seen.\n/q/addressfirstseen/address";
		die();
	}
	if(isset($param1)&&strlen($param1)>24&&strlen($param1)<36&&checkAddress($param1))
	{
		$db=dbconnect();
		$result=pg_fetch_assoc(dbquery($db,"SELECT time AT TIME ZONE 'UTC' AS time FROM keys JOIN blocks ON keys.firstseen=blocks.hash WHERE address=$1;",array($param1)));
		$result=$result["time"];
		if(is_null($result))
		{
			$result="Never seen";
		}
		echo $result;
		
	}
	else
	{
		senderror(400);
		echo "ERROR: invalid address";
	}
}
else if($page=="nethash")
{
	$db=dbconnect();
	if(!isset($param1))
	{
		$param1=144;
	}
	$param1=(int)$param1;
	if(empty($param1)||!($param1>4&&$param1<10001))
	{
		senderror(400);
		echo "ERROR: invalid stepping (must be 5-10,000)";
		die();
	}
echo "Each row contains some info about the single block at height blockNumber:
- Time when the block was created (UTC)
- Decimal target
- Difficulty
- The average number of hashes it takes to solve a block at this difficulty

Each row also contains stats that apply to the set of blocks between blockNumber and the previous blockNumber:
- Average interval between blocks.
- Average target over these blocks. This is only different from the block target when
a retarget occurred in this section. (I'm not totally sure I'm doing this correctly.)
- The estimated number of network-wide hashes per second during
this time, calculated from the average interval and average target.\n
";
	echo "blockNumber,time,target,avgTargetSinceLast,difficulty,hashesToWin,avgIntervalSinceLast,netHashPerSecond\n";
	echo "START DATA\n";
	$query=dbquery($db,"SELECT number, EXTRACT ('epoch' FROM time) AS time, bits, round(EXTRACT ('epoch' FROM (SELECT avg(a.time) FROM (SELECT time-lag(time,1) OVER (ORDER BY time) AS time FROM blocks WHERE number>series AND number<series+($1+1)) AS a))::numeric,0) AS avg FROM blocks, generate_series(0,(SELECT max(number) FROM blocks),$1) AS series(series) WHERE number=series+$1;",array($param1));
	$onerow=pg_fetch_assoc($query);
	
	while($onerow)
	{
		$number=$onerow["number"];
		$time=$onerow["time"];
		$target=decodeCompact($onerow["bits"]);
		
		if(empty($target))
		{
			senderror(500);
			echo "ERROR: divide by zero";
			die();
		}
		
		//average targets to get accurate estimates
		if(isset($prevtarget))
		{
			$avgtarget=bcdiv(bcadd($target,$prevtarget),"2",0);
		}
		else
		{
			$avgtarget=$target;
		}
		$prevtarget=$target;
		
		$difficulty=bcdiv("26959535291011309493156476344723991336010898738574164086137773096960",$target,2);
		$hashestowin=bcdiv("1",bcdiv($target,"115792089237316195423570985008687907853269984665640564039457584007913129639935",55),0);
		$avginterval=$onerow['avg'];
		$avghashestowin=bcdiv("1",bcdiv($avgtarget,"115792089237316195423570985008687907853269984665640564039457584007913129639935",55),0);
		$nethash=bcdiv($avghashestowin,$avginterval,0);
		echo "$number,$time,$target,$avgtarget,$difficulty,$hashestowin,$avginterval,$nethash\n";
		$onerow=pg_fetch_assoc($query);
	}
	
	/*for($i=0;$i<getblockcount();$i+=144)
	{
		$start=$i;
		$stop=$start+144;
		$onerow=pg_fetch_array(dbquery($db,"SELECT bstat.number AS number,bstat.time AS time,bstat.bits AS bits,round((EXTRACT ('epoch' FROM bavg.time))::numeric,0) AS avg FROM (SELECT avg(time.time)::interval AS time FROM (SELECT time-lag(time,1) OVER (ORDER BY time) AS time FROM blocks WHERE blocks.number>$1 AND blocks.number<($2+1)) AS time) AS bavg, (SELECT number,EXTRACT ('epoch' FROM time) AS time,bits FROM blocks WHERE number=$2) AS bstat;",array($start,$stop)));
		$number=$onerow['number'];
		$time=$onerow['time'];
		$target=decodeCompact($onerow['bits']);
		if(empty($target)||$target==0)
		{
			die();
		}
		$hashesToWin=bcdiv("1",bcdiv($target,"115792089237316195423570985008687907853269984665640564039457584007913129639935",55),0);
		$intervalSinceLast=$onerow['avg'];
		$nethash=bcdiv($hashesToWin,$intervalSinceLast,0);
		echo "$number,$time,$target,$hashesToWin,$intervalSinceLast,$nethash\n";
		
	}*/
	die();
}
else if ($page=="mytransactions")
{
	//This RELIES on the fact that only address transactions will be sent/received
	ini_set("zlib.output_compression","On");
	
	function cache($etag)
	{
		$version=1;
		$etag=(string)$etag."-".$version;
		$baseetag=$etag;
		$etag="W/\"$etag\"";
		header("ETag: $etag");
		
		if(isset($_SERVER['HTTP_IF_NONE_MATCH'])&&!is_null($_SERVER['HTTP_IF_NONE_MATCH'])&&!empty($_SERVER['HTTP_IF_NONE_MATCH']))
		{
			$tags=stripslashes($_SERVER['HTTP_IF_NONE_MATCH']);
			$tags=preg_split("/, /",$tags );
			foreach($tags as $tag)
			{
				if($tag==$etag)
				{
					header($_SERVER["SERVER_PROTOCOL"]." 304 Not Modified");
					die();
					
				}
			}
		}
		else
		{
			return false;
		}
	}
	
	$db=dbconnect();
	if(empty($param1))
	{
		echo "Returns all transactions sent or received by the period-separated Bitcoin
addresses in parameter 1. The optional parameter 2 contains a hexadecimal block
hash: transactions in blocks up to and including this block will not be returned.

The transactions are returned as a JSON object. The object's \"keys\" are transaction
hashes. The structure is like this (mostly the same as jgarzik's getblock):
root
 transaction hash
  hash (same as above)
  version
  number of inputs
  number of outputs
  lock time
  size (bytes)
  inputs
   previous output
    hash of previous transaction
    index of previous output
   scriptsig (replaced by \"coinbase\" on generation inputs)
   sequence (only when the sequence is non-default)
   address (on address transactions only!)
  outputs
   value
   scriptpubkey
   address (on address transactions only!)
  block hash
  block number
  block time

Only transactions to or from the listed *addresses* will be shown. Public key transactions
will not be included.

When encountering an error, the response will start with \"ERROR:\", followed by
the error. An appropriate HTTP response code will also be sent. A response with no body
must also be considered to be an error.

/q/mytransactions/address1.address2/blockHash";
		die();
	}
	if(!empty($param2))
	{
		$param2=remove0x(trim(strtolower($param2)));
		if(preg_match("/[0-9a-f]{64}/",$param2)!==1)
		{
			echo "ERROR: block limit is in invalid format";
			senderror(400);
			die();
		}
		$blocklimit=pg_fetch_assoc(pg_query_params($db,"SELECT number FROM blocks WHERE hash=decode($1,'hex');",array($param2)));
		$blocklimit=(int)$blocklimit["number"];
		if(empty($blocklimit))
		{
			$blocklimit=0;
		}
	}
	else
	{
		$blocklimit=0;
	}
	//gather addresses
	$addresses=explode('.',trim($param1));
	foreach($addresses as &$address)
	{
		if(preg_match("/^[1-9A-HJ-NP-Za-km-z]{25,44}$/",$address)!==1||!checkAddress($address))
		{
			echo "ERROR: One or more addresses are invalid";
			senderror(400);
			die();
		}
		$address="decode('".addressToHash160($address)."','hex')";
	}
	//this is safe because addresses were checked above
	$addresses=implode(",",$addresses);
	$sql=<<<EOF
SELECT encode(blocks.hash,'hex') AS block, encode(transactions.hash,'hex') AS tx, blocks.number AS blocknum, blocks.time AT TIME ZONE 'UTC' AS time, transactions.id AS tid, transactions.raw AS rawtx
FROM inputs JOIN transactions ON (inputs.tx=transactions.hash) JOIN blocks ON (inputs.block=blocks.hash)
WHERE inputs.type='Address' AND blocks.number>$1 AND inputs.hash160 IN ($addresses)
UNION
SELECT encode(blocks.hash,'hex') AS block, encode(transactions.hash,'hex') AS tx, blocks.number AS blocknum, blocks.time AT TIME ZONE 'UTC' AS time, transactions.id AS tid, transactions.raw AS rawtx
FROM outputs JOIN transactions ON (outputs.tx=transactions.hash) JOIN blocks ON (outputs.block=blocks.hash)
WHERE outputs.type='Address' AND blocks.number>$1 AND outputs.hash160 IN ($addresses)
ORDER BY tid;
EOF;
	$result=pg_query_params($db,$sql,array($blocklimit));
	$return=(object)array();
	$maxrow=pg_num_rows($result);
	$counter=0;
	while($row=pg_fetch_assoc($result))
	{
		$rowtx=json_decode($row["rawtx"]);
		$block=$row["block"];
		$blocknum=$row["blocknum"];
		$time=$row["time"];
		$tx=$row["tx"];
		
		//caching
		$counter++;
		if($counter==$maxrow)
		{
			cache($block);
		}
		
		//add additional info
		$rowtx->block=$row["block"];
		$rowtx->blocknumber=$row["blocknum"];
		$rowtx->time=$row["time"];
		
		//add addresses
		foreach($rowtx->in as &$i)
		{
			if(isset($i->scriptSig))
			{
				$scriptsig=$i->scriptSig;
				$simplescriptsig=preg_replace("/[0-9a-f]+ OP_DROP ?/","",$scriptsig);
				if(preg_match("/^[0-9a-f]+ [0-9a-f]{130}$/",$simplescriptsig))
				{
					$pubkey=preg_replace("/^[0-9a-f]+ ([0-9a-f]{130})$/","$1",$simplescriptsig);
					$hash160=strtolower(hash160($pubkey));
					$address=hash160ToAddress($hash160);
					$i->address=$address;
				}
			}
		}
		foreach($rowtx->out as &$i)
		{
			$scriptpubkey=$i->scriptPubKey;
			$simplescriptpk=preg_replace("/[0-9a-f]+ OP_DROP ?/","",$scriptpubkey);
			if(preg_match("/^OP_DUP OP_HASH160 [0-9a-f]{40} OP_EQUALVERIFY OP_CHECKSIG$/",$simplescriptpk))
			{
				$hash160=preg_replace("/^OP_DUP OP_HASH160 ([0-9a-f]{40}) OP_EQUALVERIFY OP_CHECKSIG$/","$1",$simplescriptpk);
				$address=hash160ToAddress($hash160);
				$i->address=$address;
			}
		}
		
		$return=(array)$return;
		$return[$tx]=$rowtx;
	}
	echo indent(json_encode($return));
	/*
	$maxrow=pg_num_rows($result)-1;
	$counter=0;
	$return=array();
	while($counter<=$maxrow)
	{
		$counter++;
		$row=pg_fetch_assoc($result,$counter);
		
		$tx=$row["tx"];
		$rowtx=json_decode($row["rawtx"]);		
		
		if(!isset($return[$counter])) //if this is a new transaction
		{			
			$rowtx->block=$row["block"];
			$rowtx->blocknumber=$row["blocknum"];
			$rowtx->time=$row["time"];
			
			//create index of inputs for performance
			$inputindex=array();
			foreach($rowtx->in as $c=>$i)
			{
				$key=$i->prev_out->hash.$i->prev_out->n;
				$inputindex[$key]=$c;
			}
		}
		
		//Add addresses to inputs/outputs
		
		
		/*$type=$row["type"];
		$address=hash160ToAddress($row["hash160"]);
		if($type=="in")
		{
			$prev=$row["prev"];
			$index=$row["index"];
			$indexpos=$inputindex[$prev.$index];
			$rowtx->in[$indexpos]->address=$address
			/*$ainputindex=array();
			$ainputprev=array();
			$iindex=0;
			foreach($rowtx->in as &$i)
			{
				if($i->prev_out->hash==$prev&&$i->prev_out->n==$index)
				{
					$i->address=$address;
				}
			}
		}
		else //if type==out
		{
			$index=$row["id"];
			$rowtx->out[$counter]->address=$address;
		}
	}*/

}
else //no matching page
{
	senderror(404);
	echo "ERROR: invalid query";
}
