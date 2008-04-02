#!/usr/bin/php
<?
require('register.php');

class foo
{
	private $debug = 0;
	private function _msg(&$renderer, $type, $msg)
	{
		$bt = debug_backtrace();
		$renderer->doc .= "<br><b>{register plugin $type}:".$bt[2]["function"]."():".$bt[1]["line"].": $msg</b><br>";
		unset($bt);
	}
	private function err(&$renderer, $msg) { $this->_msg($renderer, "error", $msg); }
	private function dbg(&$renderer, $msg) { if ($this->debug) $this->_msg($renderer, "debug", $msg); }

	private function push_bit(&$bits, &$bit)
	{
		if (!array_key_exists("name", $bit))
			$bit["name"] = "ERROR:UNDEF";
		if (!array_key_exists("desc", $bit))
			$bit["desc"] = "";
		if (!array_key_exists("flags", $bit))
			$bit["flags"] = "";
		$range = explode(" ", $bit["range"]);
		array_push($bits, array($range[0], $range[1], $bit["name"], $bit["desc"], $bit["flags"]));
		$bit = array();
	}
	private function parse_match(&$renderer, $match)
	{
		$keys = array();
		$bits = array();
		$bit = array();
		$lines = explode("\n", $match);
		foreach ($lines as $l) {
			if ($l == "")
				continue;
			$this->dbg($renderer, "line: $l");
			$val = strstr($l, " = ");
			$key = substr($l, 0, strlen($l) - strlen($val));
			$val = str_replace("\\n", "\n", substr($val, 3));
			if (substr($key, 0, 4) == "bit ") {
				$subkey = substr($key, 4);
				if ($subkey == "range" && count($bit) > 0)
					$this->push_bit($bits, $bit);
				$bit[$subkey] = $val;
				$this->dbg($renderer, "BIT[$subkey] = $val");
			} else {
				$keys[$key] = $val;
				$this->dbg($renderer, "KEY[$key] = $val");
			}
		}
		if (count($bit) > 0)
			$this->push_bit($bits, $bit);
		return array($keys, $bits);
	}

	public function testit($match) { return $this->parse_match($this, $match); }
}

function test_match($match)
{
	$test = new foo();
	list($keys, $bits) = $test->testit($match);

		$reg = new register(
			$keys["register"], $keys["long desc"], $keys["addr"], $keys["reset"],
			$keys["length"], $keys["perms"], $keys["short desc"],
			$bits
		);
		if (!$reg->render($file))
			return false;
		unset($reg);

		/* pass the WDOG_CTL back up for "alt" in <img>  ? */
		//return array($keys["register"], $keys["long desc"]);
		return true;

}

test_match("
register = DTLB_BASEn
long desc = Data TLB Base register N (replicated 32 times)
perms = RW
reset = 0x00000000
length = 32
bit range = 31 9
bit name = PADR
bit desc = TLB Page (virtual) Address.\n  Depending on the page size (PSZ) bit in the\ncorresponding DTLB_ATTRn register, some of these bits\nare &quot;don't care&quot;.
bit range = 8 8
bit name = ACTIVE
bit desc = TLB can only hit when this bit is set,\nit is ignored otherwise.
bit range = 7 0
bit name = KEY
bit desc = Key Specifier.\nThe Key specified by these bits must sufficiently match the\nmask specified by the DTLB_MASK register in order for this\npage descriptor to be valid (active). If the KEY is set to\n0x00, checking against the mask is defeated, and this pages\nis treated as always matching the mask.
");

test_match("
register = DTLB_ATTRn
long desc = Data TLB Attribute register N (replicated 32 times)
perms = RW
reset = 0x00000000
length = 32
bit range = 31 12
bit name = TADR
bit desc = TLB Translation (physical) Address.\nThese bits specify the address translation\nto be applied to accesses that hit this page.\nFor a 4MB page, bits 21:12 should be set to 0.
bit range = 11 9
bit name = RES
bit desc =  Reserved for the operating system.\nThe value has no effect on the hardware.
bit range = 8 8
bit name = SWR
bit desc = Supervisor Mode Write Access Enable
bit range = 7 7
bit name = UWR
bit desc = User Mode Write Access Enable
bit range = 6 6
bit name = URD
bit desc = User Mode Read Access Enable
bit range = 5 5
bit name = AMOD
bit desc = Access Modifier.\n0 = Memory,\n1 = IO Device
bit range = 4 4
bit name = DIRTY
bit desc = Dirty Page Indicator.\nA protection violation exception is generated on\nstore accesses to this page when this bit is set to 0.
bit range = 3 2
bit name = CMD
bit desc = Cache Mode Specifier.\n00 = Page is Non-Cacheable\n01 = Page is Write Back Cacheable\n10 = Page is Write Through Cacheable With No Allocate On Write\n11 = Page is Write Through Cacheable With Allocate On Write
bit range = 1 1
bit name = V
bit desc =  1 = TLB is valid,\n0 = TLB is invalid and causes fault exception.
bit range = 0 0
bit name = PSZ
bit desc = 0 = 4KB page,\n1 = 4MB page.
");
?>
