<?
/**
 * Register Class: display pretty pictures of registers
 *
 * @license   GPL-3
 * @author    Mike Frysinger <vapier@gentoo.org>
 */

/* Register Diagram Conventions (from BF537 HRM)
 *  Register diagrams use the following conventions:
 * The descriptive name of the register appears at the top, followed by
 *  the short form of the name in parentheses (see TableP-1).
 * If the register is read-only (RO), write-1-to-set (W1S), or
 *  write-1-to-clear (W1C), this information appears under the name.
 *  Read/write is the default and is not noted. Additional descriptive
 *  text may follow.
 * If any bits in the register do not follow the overall read/write con-
 *  vention, this is noted in the bit description after the bit name.
 * If a bit has a short name, the short name appears first in the bit
 *  description, followed by the long name in parentheses.
 * The reset value appears in binary in the individual bits and in hexa-
 *  decimal to the right of the register.
 * Bits marked x have an unknown reset value. Consequently, the
 *  reset value of registers that contain such bits is undefined or depen-
 *  dent on pin values at reset.
 * Shaded bits are reserved.
 *  To ensure upward compatibility with future implementations,
 *  write back the value that is read for reserved bits in a register,
 *  unless otherwise specified.
 */

/* TODO:
 *	x handle bit ranges that split across the 16bit boundary (WDOG_CNT)
 *	x register sub captions (RTC_ISTAT)
 *	- partial undefines (RTC_ICTL)
 *	- long bit descriptions may overlap vertical lines
 *	- SUB_LABELS: bit name explanations (PLL_CTL -> MSEL -> Multiplier Select)
 *	x try and balance descriptions better rather than by bit pos (PLL_CTL)
 *	- if register display does not span multiple banks, only show Reset on right
 *  - try and get a better size estimate instead of starting out with 1x1
 * WISHLIST:
 *	- add support for arbitrary bit fields (more than just registers)
 */

define("W1C", 0x1);
class bit {
	var $start, $end, $name, $desc, $flags;
	public function bit($data)
	{
		$data = array_pad($data, 5, 0);
		$this->start = $data[0];
		$this->end   = $data[1];
		$this->name  = $data[2];
		$this->desc  = $data[3];
		if (gettype($data[4]) == "string") {
			$flags = explode(" ", $data[4]);
			foreach ($flags as $f)
				switch ($f) {
				case "W1C": $this->flags |= W1C; break;
				}
		} else
			$this->flags = $data[4];
	}
	public function bit_range($bit_high = -1, $bit_range = -1)
	{
		if ($bit_high != -1 && $this->start != $this->end)
			return array(min($this->start, $bit_high), max($this->end, $bit_high - $bit_range + 1));
		else
			return array($this->start, $this->end);
	}
	public function format_name($bit_high = -1, $bit_range = -1)
	{
		$ret = $this->name;
		if ($this->start != $this->end) {
			$range = $this->bit_range($bit_high, $bit_range);
			$bit_start = $range[0] - $this->end;
			$bit_end = $range[1] - $this->end;
			$ret .= "[" . $bit_start . ":" . $bit_end . "]";
		}
		if ($this->flags & W1C)
			$ret .= " (W1C)";
		return $ret;
	}
}

define("FONT_TITLE", 0);
define("FONT_BIT_LABELS", 1);
define("FONT_BITS", 2);
define("FONT_LABELS", 3);
define("FONT_DESC", 4);
class im {
	private $im;
	public $grey, $white, $black;
	public function im($max_x = 1, $max_y = 1)
	{
		/* create a transparent image */
		$im = imagecreatetruecolor($max_x, $max_y);
		imagesavealpha($im, true);
		$trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
		imagefill($im, 0, 0, $trans);

		$this->grey = imagecolorallocate($im, 210, 210, 210);
		$this->white = imagecolorallocate($im, 255, 255, 255);
		$this->black = imagecolorallocate($im, 0, 0, 0);

		$this->im = $im;
		return $im;
	}
	public function enlarge($newsx, $newsy)
	{
		/* make sure image area is large enough */
		$oldsx = imagesx($this->im);
		$oldsy = imagesy($this->im);
		if ($newsx < $oldsx && $newsy < $oldsy)
			return;

		$newsx = max($newsx, $oldsx);
		$newsy = max($newsy, $oldsy);
		//print_r(debug_backtrace());
		//echo "enlarging image from ${oldsx}x${oldsy} to ${newsx}x${newsy}\n";
		$oldim = $this->im;
		$this->im($newsx, $newsy);
		imagecopy($this->im, $oldim, 0, 0, 0, 0, $oldsx, $oldsy);
		imagedestroy($oldim);
	}
	public function output($filename)
	{
		return imagepng($this->im, $filename, 9);
	}
	public function destroy()
	{
		return imagedestroy($this->im);
	}

	public function rect($x, $y, $w, $h, $col, $fill = "")
	{
		$this->enlarge($w + 1, $h + 1);
		$this->enlarge($x + 1, $y + 1);
		if ($fill == "")
			imagerectangle($this->im, $x, $y, $w, $h, $col);
		else
			imagefilledrectangle($this->im, $x, $y, $w, $h, $col);
		//echo "rect($x, $y, $w, $h, ...);\n";
		//$this->text($x, $y, FONT_BIT_LABELS, "$x");
	}
	public function line($x1, $y1, $x2, $y2)
	{
		$this->enlarge(max($x1, $x2) + 1, max($y1, $y2) + 1);
		imageline($this->im, $x1, $y1, $x2, $y2, $this->black);
	}

	private $text_range = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-+^()[]{}";
	private $font_path = "/usr/share/fonts/ttf-bitstream-vera";
	private $font_sizes = array(15, 10, 10, 12, 10);
	/* mono: VeraMono mono_bold: VeraMoBd sans_bold: VeraBd serif: Vera */
	private $font_files = array("VeraBd", "VeraMono", "VeraMoBd", "VeraMoBd", "Vera");

	private function font_path($fontidx)
	{
		return $this->font_path . "/" . $this->font_files[$fontidx] . ".ttf";
	}
	private function font_dims($fontidx, $text, $angle = 0)
	{
		return imagettfbbox($this->font_sizes[$fontidx], $angle, $this->font_path($fontidx), $text);
	}
	private function _font_height(array $box) { return $box[1] - $box[7]; }
	public function font_height($fontidx, $text = "", $angle = 0)
	{
		if ($text == "")
			$text = $this->text_range;
		return $this->_font_height($this->font_dims($fontidx, $text, $angle));
	}
	private function _font_width(array $box) { return $box[2] - $box[0]; }
	public function font_width($fontidx, $text, $angle = 0)
	{
		return $this->_font_width($this->font_dims($fontidx, $text, $angle));
	}
	private function _text($x, $y, $fontidx, $text, $height_text, $angle = 0)
	{
		$fh = $this->font_height($fontidx, $height_text, $angle);
		$fw = $this->font_width($fontidx, " $text ", $angle);

		$this->enlarge($x + $fw, $y + $fh * 1.5);

		return imagettftext($this->im, $this->font_sizes[$fontidx], $angle, $x,
				$y + $fh, $this->black, $this->font_path($fontidx), $text);
	}
	public function exact_text($x, $y, $fontidx, $text, $angle = 0)
	{
		return $this->_text($x, $y, $fontidx, $text, $text, $angle);
	}
	public function text($x, $y, $fontidx, $text, $angle = 0)
	{
		return $this->_text($x, $y, $fontidx, $text, "", $angle);
	}
}

class register {
	var $name, $desc, $mmr_addr, $maxbits, $bits, $resetval, $bitrange, $perms, $sub_desc;
	public function register($name, $desc, $mmr_addr, $resetval, $maxbits, $perms, $sub_desc, $bits)
	{
		$this->name = $name;
		$this->desc = $desc;
		$this->mmr_addr = $mmr_addr + 0; /* force conversion to int */
		if ($resetval !== "undef")
			$resetval += 0;
		$this->resetval = $resetval;
		$this->perms = $perms;
		$this->sub_desc = $sub_desc;
		$this->bits = array();
		$this->maxbits = $maxbits;
		foreach ($bits as $bit) {
			$b = new bit($bit);
			//print_r($b);
			$this->bits[$b->start] = $b;
		}
		$this->bitrange = 16;
	}
	function bit_find($bit)
	{
		foreach ($this->bits as $b) {
			if ($bit <= $b->start && $bit >= $b->end)
				return $b;
		}
		return false;
	}
	function bit_defined($bit)
	{
		return ($this->bit_find($bit) != false);
	}

	private function bitpos($bit, $bitdim, $bitmax)
	{
		$inv = abs($bit - $bitmax);
		return ($inv * $bitdim) + floor($inv / 4) + 1;
	}
	private function bitrange($bits, $upper, $lower)
	{
		$upper++; $lower++; /* they ask for the 15th bit so we want to shift 16 ... */
		return ($bits & ((1 << $upper) - 1) ^ ((1 << $lower) - 1)) >> $lower;
	}
public function render($output_file) {
	$register = $this;

	/* setup some image stuff to play with */
	$im = new im();
	$bitdim = $im->font_height(FONT_BITS, "01X") * 3;
	$x = 0;
	$y = 0;

	/* draw register description */
	$text = $register->name;
	if ($register->desc !== "")
		$text .= ": " . $register->desc;
	if ($register->perms !== "")
		$text .= " - " . $register->perms;
	$im->text($x, $y, FONT_TITLE, $text);
	$ymin = $im->font_height(FONT_TITLE);

	/* draw register sub desc if applicable */
	if ($register->sub_desc !== "") {
		$im->text($x, $y + $ymin, FONT_DESC, " ".$register->sub_desc);
		$ymin *= 2;
	} else
		$ymin *= 1.5;

	/* draw MMR address and complete reset desc */
	if ($register->mmr_addr === "sysreg")
		$mmr_disp = sprintf("System Register");
	else if ($register->mmr_addr === 0)
		$mmr_disp = "";
	else
		$mmr_disp = sprintf("MMR = 0x%08X", $register->mmr_addr);
	$reset_disp = sprintf("Reset = 0x%0" . ($register->maxbits / 4) . "X", $register->resetval);

	$mmrsx = $im->font_width(FONT_LABELS, $mmr_disp);
	$resetsx = $im->font_width(FONT_LABELS, $reset_disp);
	$xmin = max($mmrsx, $resetsx);

	$y = $ymin;
	$im->text($xmin - $mmrsx, $y, FONT_LABELS, $mmr_disp);
	if ($register->resetval !== "undef") {
		$y += $im->font_height(FONT_LABELS);
		$im->text($xmin - $resetsx, $y, FONT_LABELS, $reset_disp);
	}

	$xmin += $bitdim;
	$ymin += $bitdim;

	/* find the largest desc text string so we dont underflow the image */
	foreach ($register->bits as $bit)
		$xmin = max($xmin, $im->font_width(FONT_LABELS, $bit->format_name()) + $bitdim);

	/* break the register up into groups of 16 bits */
for ($bitset = $register->maxbits; $bitset > 0; $bitset -= $register->bitrange) {
	$bitset_h = $bitset - 1;
	$bitset_l = $bitset - $register->bitrange - 1;
	$bitset_m = ($bitset_h - $bitset_l) / 2 + $bitset_l;
//echo "Processing bitset $bitset_l . $bitset_m . $bitset_h\n";

	/* balance the labels between the left and right */
	$bitset_m_set = array();
	for ($b = $bitset_h; $b > $bitset_l; $b--) {
		$bit = $register->bit_find($b);
		if ($bit == false)
			continue;
		$range = $bit->bit_range($bitset_h, $register->bitrange);
		array_push($bitset_m_set, $b);
		$b -= ($range[0] - $range[1]);
	}
	if (count($bitset_m_set) == 0)
		echo "BAD!!: bitset_m_set is 0!\n";
	else
		$bitset_m = $bitset_m_set[count($bitset_m_set) / 2];
//echo " adjusted mid to [".count($bitset_m_set)."/2] $bitset_m\n";

	/* first draw the register boxes */
	$x = $register->bitpos($bitset_h, $bitdim, $bitset_h) + $xmin - 1;
	$y = $ymin;
	$im->line($x, $y-$bitdim*0.2, $x, $y+$bitdim*1.2);
	$im->line($x+1, $y-$bitdim*0.2, $x+1, $y+$bitdim*1.2);
	for ($b = $bitset_h; $b > $bitset_l; $b--) {
		$x = $register->bitpos($b, $bitdim, $bitset_h) + $xmin;
		$bc = ($register->bit_defined($b) ? $im->white : $im->grey);
		$im->rect($x, $y, $x+$bitdim, $y+$bitdim, $bc, "fill");
		$im->rect($x, $y, $x+$bitdim, $y+$bitdim, $im->black);
		/* draw a slightly thicker verticle line in between nibbles */
		if ($b % 4 == 0) {
			$im->line($x+$bitdim, $y-$bitdim*0.2, $x+$bitdim, $y+$bitdim*1.2);
			$im->line($x+$bitdim+1, $y-$bitdim*0.2, $x+$bitdim+1, $y+$bitdim*1.2);
		}

		/* draw the bit pos marker */
		$fw = $im->font_width(FONT_BITS, $b);
		$xoff = ($bitdim - $fw) / 2;
		$yoff = $im->font_height(FONT_BITS) * 1.25;
		$im->text($x+$xoff, $y-$yoff, FONT_BITS, $b);

		/* draw the default bit value */
		if ($register->resetval === "undef")
			$bit_disp = "x";
		else
			$bit_disp = ($register->resetval & (0x1 << $b) ? "1" : "0");
		$xoff = ($bitdim - $im->font_width(FONT_BIT_LABELS, $bit_disp)) / 2;
		$yoff = ($bitdim - $im->font_height(FONT_BIT_LABELS, $bit_disp)) / 2;
		$im->exact_text($x+$xoff, $y+$yoff, FONT_BIT_LABELS, $bit_disp);
	}

	/* draw the partial reset value to the right of this set of bits */
	$x = $register->bitpos($bitset_l, $bitdim, $bitset_h) + $xmin;
	$bit_disp = "Reset = ";
	if ($register->resetval === "undef")
		$bit_disp .= "undefined";
	else
		$bit_disp .= sprintf("0x%04X", $this->bitrange($register->resetval, $bitset_h, $bitset_l));
	$yoff = ($bitdim - $im->font_height(FONT_BIT_LABELS, $bit_disp)) / 2;
	$im->exact_text($x+$xoff, $y+$yoff-1, FONT_LABELS, $bit_disp);

	/* now draw the lines underneath -- first the left, then the right */
	$x = $xmin;
	$y = $ymin + $bitdim * 1.2;
	$xoff = $bitdim / 2;
	$yadd = $bitdim * 3;
	for ($i = 0; $i < 2; ++$i) {
		if ($i == 0) {
			$b_start = $bitset_h;
			$b_end = $bitset_m;
			$b_inc = -1;

			/* precalc the left width so all text is aligned */
			$desc_adjust = 0;
			for ($b = $b_start; $b*$b_inc < $b_end*$b_inc; $b += $b_inc) {
				$bit = $register->bit_find($b);
				if ($bit == false)
					continue;
				$text = $bit->format_name($bitset_h, $register->bitrange);
				$desc_adjust = max($desc_adjust, $im->font_width(FONT_LABELS, $text." "));
				$b += ($bit->start - $bit->end) * $b_inc;
			}
		} else {
			$b_start = $bitset_l + 1;
			$b_end = $bitset_m + 1;
			$b_inc = 1;
			$desc_adjust = $im->font_width(FONT_LABELS, " ");
		}
		$num_def = 0;
		$yoff = $bitdim / 2;

//echo "--- $i $b_start $b_end $b_inc\n";
	for ($b = $b_start; $b*$b_inc < $b_end*$b_inc; $b += $b_inc) {
		$bit = $register->bit_find($b);
//echo "Looking for bit $b: " . ($bit == false ? "no" : "yes") . "\n";
		if ($bit == false)
			continue;

		$text = $bit->format_name($bitset_h, $register->bitrange);
		$range = $bit->bit_range($bitset_h, $register->bitrange);
		$x = $register->bitpos($range[0], $bitdim, $bitset_h) + $xmin;

		if ($bit->start != $bit->end) {
			/* range of bits - draw the bracket */
			$cx1_l = $x + $bitdim / 4;
			$cy1 = $y + $bitdim / 4;
			$im->line($cx1_l, $y, $cx1_l, $cy1);	/* left bar */
			$cx1_r = $register->bitpos($range[1], $bitdim, $bitset_h) + $xmin;
			$cx1_r += $bitdim - $bitdim / 4;
			$im->line($cx1_r, $y, $cx1_r, $cy1);	/* right bar */
			$im->line($cx1_l, $cy1, $cx1_r, $cy1);	/* lower bar */
			$cx1 = $cx1_l + ($cx1_r - $cx1_l) / 2;
		} else {
			/* just one bit */
			$cx1 = $x + $xoff;
			$cy1 = $y + $bitdim / 4;
			$im->line($cx1, $y, $cx1, $cy1);
		}

		/* lines from bottom of selection to text */
		$cy2 = $cy1 + $bitdim * $num_def++ + $yoff;
		$fw = $desc_adjust * $b_inc;
		if ($b > $bitset_m) {
			$cx2 = $xmin + $register->bitpos($b_start, $bitdim, $bitset_h) - $bitdim / 2;
			$cx2_indent = $desc_adjust - $im->font_width(FONT_LABELS, $text." ");
		} else {
			$cx2 = $xmin + $register->bitpos(-1, $bitdim, $bitset_h % $register->bitrange) + $bitdim / 2;
			$cx2_indent = 0;
		}
		$im->line($cx1, $cy1, $cx1, $cy2);	/* vert */
		$im->line($cx1, $cy2, $cx2 - $cx2_indent, $cy2);	/* horiz */

		/* bit name */
		$fh = $im->font_height(FONT_LABELS) / 2;
		$im->text($cx2+$fw, $cy2-$fh, FONT_LABELS, $text);
		/* bit description */
		if ($bit->desc != "") {
			if ($range[1] == $bit->end)
				$bdesc = $bit->desc;
			else
				$bdesc = "See below";
			$fw += $im->font_width(FONT_DESC, "  ");
			$text = explode("\n", $bdesc);
			foreach ($text as $t) {
				$im->text($cx2+$fw, $cy2+$fh, FONT_DESC, $t);
				$fh += $im->font_height(FONT_DESC);
			}
			$yoff += $fh;
		}

		$b += ($range[0] - $range[1]) * $b_inc;
	}
//echo "$i $yadd " . ($bitdim * ++$num_def + $yoff) ."\n";
	$yadd = max($yadd, $bitdim * ($num_def+2) + $yoff);
	}
//echo "+++ $y $yadd\n";
	$ymin += $yadd;
}

	/* cleanup */
	$ret = $im->output($output_file);
	$im->destroy();
	return $ret;
}
}
?>
