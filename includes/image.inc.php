<?php

/*
    MyBlitzortung - a tool for participants of blitzortung.org
	to display lightning data on their web sites.

    Copyright (C) 2011  Tobias Volgnandt

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/


if (!defined('BO_VER'))
	exit('No BO_VER');


// returns png-image for map-marker
function bo_icon($icon)
{
	$dir = BO_DIR."cache/icons/";
	$file = $dir.$icon.'.png';

	$s = 11;

	if (!file_exists($file))
	{
		$c = floor($s/2);

		$im = ImageCreate($s, $s);
		$bg = imagecolorallocate($im, 255, 255, 255);
		$trans = imagecolortransparent($im, $bg);
		imagefill($im,0,0,$trans);

		$col = ImageColorAllocate ($im, hexdec(substr($icon,0,2)), hexdec(substr($icon,2,2)), hexdec(substr($icon,4,2)));
		imagefilledellipse( $im, $c, $c, $c+2, $c+2, $col );

		$tag = intval(substr($icon,6,1));
		if ($tag >= 1)
		{
			$col = ImageColorAllocate ($im, 0,0,0);
			imageellipse( $im, $c, $c, $c+$tag, $c+$tag, $col );
		}
		
		Imagepng($im, $file);
		ImageDestroy($im);
	}
	
	Header("Content-type: image/png");
	readfile($file);

	exit;
}

function bo_tile()
{
	@set_time_limit(5);

	global $_BO;

	session_write_close();
	
	if (rand(0, 3000) == 1)
	{
		register_shutdown_function('bo_delete_files', BO_DIR.'cache/tiles/', 24, 3);
	}
	
	$x = intval($_GET['x']);
	$y = intval($_GET['y']);
	$zoom = intval($_GET['zoom']);
	$only_own = intval($_GET['own']);
	$only_info = isset($_GET['info']);

	$caching = !(defined('BO_CACHE_DISABLE') && BO_CACHE_DISABLE === true);
	$time = time();
	
	//Debug
	//$time = strtotime('2011-06-16 17:00:00 UTC');
	
	//get config
	if (isset($_GET['count'])) // display strike count
	{
		$count_types = explode(',',$_GET['count']);

		$type = 0;
		$time_range = 0;
		$time_start = 0;
		$update_interval = 0;
		
		$time_min = array();
		$time_max = array();
		$update_interval = array();
		
		foreach($count_types as $i)
		{
			$type += pow(2, $i);
			
			$cfg = $_BO['mapcfg'][$i];
			
			if (!is_array($cfg) || !$cfg['upd_intv'])
				continue;
			
			$time_start = $time - 60 * $cfg['tstart'];
			
			$update_intervals[$i] = $cfg['upd_intv'];
			$times_min[$i]        = mktime(date('H', $time_start), ceil(date('i', $time_start) / $cfg['upd_intv']) * $cfg['upd_intv'], 0, date('m', $time_start), date('d', $time_start), date('Y', $time_start));
			$times_max[$i]        = $times_min[$i] + 60 * $cfg['trange'] + 59;
		}
		
		$update_interval = count($update_intervals) ? min($update_intervals) : 0;
		$time_min        = count($times_min) ? min($times_min) : 0;
		$time_max        = count($times_max) ? max($times_max) : 0;
		
		$type = 'count_'.$type;
		
	}
	else //normal strike display
	{
		$type = intval($_GET['type']);
		
		$cfg = $_BO['mapcfg'][$type];
		$time_start = $time - 60 * $cfg['tstart'];
		$time_range = $cfg['trange'];
		$update_interval = $cfg['upd_intv'];
		$c = $cfg['col'];
		$time_min   = mktime(date('H', $time_start), ceil(date('i', $time_start) / $update_interval) * $update_interval, 0, date('m', $time_start), date('d', $time_start), date('Y', $time_start));
		$time_max   = $time_min + 60 * $time_range + 59;
	}
	
	if (!$time_start || !$time_min || !$time_max)
		bo_tile_output();

	//calculate some time information
	$cur_minute = intval(intval(date('i')) / $update_interval);
	$mod_time   = mktime(date('H'), $cur_minute * $update_interval , 0);
	$exp_time   = $mod_time + 60 * $update_interval + 59;
	$age        = $exp_time - time();

	
	//Headers
	header("Last-Modified: ".gmdate("D, d M Y H:i:s", $mod_time)." GMT");
	header("Expires: ".gmdate("D, d M Y H:i:s", $exp_time)." GMT");
	header("Content-Disposition: inline; filename=\"MyBlitzortungTile.png\"");
	
	if ($caching)
	{
		header("Pragma: ");
		header("Cache-Control: public, max-age=".$age);
	}
	else
	{
		header("Pragma: no-cache");
		header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
	}

	//send only the info/color-legend image (colors, time)
	if ($only_info)
	{
		bo_load_locale();
		
		$time_max = min(bo_get_conf('uptime_strikes'), $time_max);
		$show_date = $time_max - $time_min > 3600 * 12 ? true : false;
		
		$fh = imagefontheight(BO_MAP_LEGEND_FONTSIZE);
		$w = BO_MAP_LEGEND_WIDTH;
		$h = BO_MAP_LEGEND_HEIGHT + $fh * ($show_date ? 2 : 1)+1;
		

		$I = imagecreate($w, $h);
		$col = imagecolorallocate($I, 50, 50, 50);
		imagefill($I, 0, 0, $col);

		$coLegendWidth = $w / count($c);
		foreach($c as $i => $rgb)
		{
			$color[$i] = imagecolorallocate($I, $rgb[0], $rgb[1], $rgb[2]);
			imagefilledrectangle($I, (count($c)-$i-1)*$coLegendWidth, 0, (count($c)-$i)*$coLegendWidth, BO_MAP_LEGEND_HEIGHT, $color[$i]);
		}

		
		$col = imagecolorallocate($I, 255,255,255);
		
		if ($show_date)
		{
			imagestring($I, BO_MAP_LEGEND_FONTSIZE, 2, BO_MAP_LEGEND_HEIGHT+1,  '  '.date(_BL('_dateshort').' H:i', $time_min), $col);
			imagestring($I, BO_MAP_LEGEND_FONTSIZE, 2, BO_MAP_LEGEND_HEIGHT+1 + $fh, '- '.date(_BL('_dateshort').' H:i', $time_max), $col);
		}
		else
			imagestring($I, BO_MAP_LEGEND_FONTSIZE, 2, BO_MAP_LEGEND_HEIGHT+1, date('H:i', $time_min).' - '.date('H:i', $time_max), $col);

		
		header("Content-Type: image/png");
		imagepng($I);
		exit;
	}


	//Caching
	$dir = BO_DIR.'cache/tiles/';
	$filename = $type.'_'.$zoom.'_'.$x.'x'.$y.'-'.$only_own.'-'.(bo_user_get_level() ? 1 : 0).'.png';
	
	if (BO_CACHE_SUBDIRS === true)
		$filename = strtr($filename, array('_' => '/'));

	$file = $dir.$filename;

	if (file_exists($file) && $caching)
	{
		$filetime = filemtime($file);
		$file_minute = intval(intval(date('i', $filetime)) / $update_interval);

		if ($cur_minute == $file_minute && time() - $filetime < $update_interval * 60 )
		{
			header("Content-Type: image/png");
			readfile($file);
			exit;
		}
	}

	list($lat1, $lon1, $lat2, $lon2) = bo_get_tile_dim($x, $y, $zoom);
	
	//Check if zoom or position is in limit
	$radius = $_BO['radius'] * 1000; //max. Distance
	if ($radius)
	{
		if ($zoom < BO_MAX_ZOOM_LIMIT) //set max. distance to 0 (no limit) for small zoom levels
		{
			$radius = 0;
		}
		else
		{
			list($min_lat, $min_lon) = bo_distbearing2latlong($radius * sqrt(2), 225);
			list($max_lat, $max_lon) = bo_distbearing2latlong($radius * sqrt(2), 45);

			//return text if outside of radius
			if ( 	($lat1 > $max_lat && $lat2 > $max_lat) ||
					($lat1 < $min_lat && $lat2 < $min_lat) ||
					($lon1 > $max_lon && $lon2 > $max_lon) ||
					($lon1 < $min_lon && $lon2 < $min_lon)
				)
			{

				if (!file_exists($dir.'na.png') || !$caching)
				{
					bo_load_locale();
					
					$I = imagecreate(BO_TILE_SIZE, BO_TILE_SIZE);

					$blank = imagecolorallocate($I, 255, 255, 255);
					$textcol = imagecolorallocate($I, 70, 70, 70);
					$box_bg  = imagecolorallocate($I, 210, 210, 255);
					$box_line  = imagecolorallocate($I, 255, 255, 255);

					imagefilledrectangle( $I, 0, 0, BO_TILE_SIZE, BO_TILE_SIZE, $blank);

					$text = _BL('tile not available', true);
					$lines = explode("\n", $text);
					$height = (count($lines));
					$width = 0;
					foreach($lines as $line)
						$width = max(strlen($line), $width);
					
					$fwidth  = imagefontwidth(BO_MAP_NA_FONTSIZE);
					$fheight = imagefontheight(BO_MAP_NA_FONTSIZE);

					imagefilledrectangle( $I, 25, 115, 35+$width*$fwidth, 127+$height*$fheight, $box_bg);
					imagerectangle( $I, 25, 115, 35+$width*$fwidth, 127+$height*$fheight, $box_line);

					foreach($lines as $i=>$line)
						imagestring($I, BO_MAP_NA_FONTSIZE, 30, 120+$i*$fheight, $line, $textcol);

					imagecolortransparent($I, $blank);
					
					if (!$caching)
					{
						header("Content-Type: image/png");
						imagepng($I);
						exit;
					}
					
					$ok = @imagepng($I, $dir.'na.png');
					
					if (!$ok)
						bo_image_cache_error(BO_TILE_SIZE, BO_TILE_SIZE);

				}

				header("Content-Type: image/png");
				readfile($dir.'na.png');

				exit;
			}

		}
	}

	//Display only strike count
	if (isset($_GET['count'])) 
	{
		$sql_where = '';
		foreach($count_types as $i)
		{
			$date_min = gmdate('Y-m-d H:i:s', $times_min[$i]);
			$date_max = gmdate('Y-m-d H:i:s', $times_max[$i]);
			
			$sql_where .= " OR s.time BETWEEN '$date_min' AND '$date_max' ";
		}
		
		$strike_count = 0;
		$whole_strike_count = 0;
		
		if ($_GET['stat'] == 2)
		{
			$stations = bo_stations();
			$stations_count = array();
			
			$sql = "SELECT ss.station_id sid, COUNT(s.time) cnt 
				FROM ".BO_DB_PREF."stations_strikes ss
				JOIN ".BO_DB_PREF."strikes s USE INDEX (time)
					ON s.id=ss.strike_id
				WHERE 1
					".($radius ? "AND s.distance < $radius" : "")."
					AND NOT (s.lat < $lat1 OR s.lat > $lat2 OR s.lon < $lon1 OR s.lon > $lon2)
					AND (0 $sql_where)
					".($only_own ? " AND part>0 " : "")."
				GROUP BY sid
				";
			$erg = bo_db($sql);
			while ($row = $erg->fetch_assoc())
			{
				$stations_count[$row['sid']] = $row['cnt'];
			}
		}
		
		$sql = "SELECT COUNT(time) cnt ".($only_own ? ", part>0 participated " : "")."
			FROM ".BO_DB_PREF."strikes s
			USE INDEX (time)
			WHERE 1
				".($radius ? "AND distance < $radius" : "")."
				AND NOT (lat < $lat1 OR lat > $lat2 OR lon < $lon1 OR lon > $lon2)
				AND (0 $sql_where)
				".($only_own ? " GROUP BY participated " : "")."
			";
		$erg = bo_db($sql);
		while ($row = $erg->fetch_assoc())
		{
			if ($only_own)
			{
				if ($row['participated'])
					$strike_count += $row['cnt'];
				
				$whole_strike_count += $row['cnt'];
			}
			else
				$strike_count += $row['cnt'];
		}
		
		//create tile image
		$I = imagecreate(BO_TILE_SIZE, BO_TILE_SIZE);
		imagealphablending($I, true); 
		imagesavealpha($I, true);

		$blank = imagecolorallocatealpha($I, 255, 255, 255, 127);
		imagefilledrectangle($I, 0, 0, BO_TILE_SIZE, BO_TILE_SIZE, $blank);
	
	
		//border
		$col = imagecolorallocatealpha($I, 100,100,100,50);
		imagerectangle( $I, 0, 0, BO_TILE_SIZE-1, BO_TILE_SIZE-1, $col);
		
		//number
		$textsize = BO_MAP_COUNT_FONTSIZE;
		$bold = BO_MAP_COUNT_FONTBOLD;
		$twidth = bo_imagetextwidth($textsize, $bold, $strike_count);
		$theight = bo_imagetextheight($textsize, $bold, $strike_count);
		$white = imagecolorallocatealpha($I, 255,255,255,0);
		imagefilledrectangle( $I, 0, 0, $twidth+2, $theight, $col);
		bo_imagestring($I, $textsize, 2, 2, $strike_count, $white, $bold);
		
		if ($only_own && intval($whole_strike_count))
		{
			$ratio = round($strike_count / $whole_strike_count * 100).'%';
			$twidth = bo_imagetextwidth($textsize, false, $ratio);
			imagefilledrectangle( $I, 0, $theight+1, $twidth+2, 2*$theight, $col);
			bo_imagestring($I, $textsize, 2, $theight+2, $ratio, $white, $bold);
		}
		
		//Stations
		if ($_GET['stat'] == 2)
		{
			arsort($stations_count);
			$i = 0;
			foreach($stations_count as $sid => $cnt)
			{
				$i++;
				
				$text = round($cnt / $strike_count * 100).'% ';
				$text .= trim($stations[$sid]['city']);

				$twidth = bo_imagetextwidth($textsize-1, $bold, $text);
				
				imagefilledrectangle($I, 0, ($theight*1.1)*$i, $twidth, ($theight*1.1)*($i+1)-1, $col);
				bo_imagestring($I, $textsize-1, 2, ($theight*1.1)*$i+3, $text, $white, false);
				
				if ($i >= 10)
					break;
			}
		}
		
		bo_tile_output($file, $caching, $I);
		
		exit;
	}
	
	
	/****** The main part: Get the data and display the strikes ******/
	
	$zoom_show_deviation = defined('BO_MAP_STRIKE_SHOW_DEVIATION_ZOOM') ? intval(BO_MAP_STRIKE_SHOW_DEVIATION_ZOOM) : 12;
	
	//add some space around
	if ($zoom >= $zoom_show_deviation)
		$space = 2;
	else
		$space = 0.05;

	$lat1 -= ($lat2-$lat1) * $space;
	$lon1 -= ($lon2-$lon1) * $space;
	$lat2 += ($lat2-$lat1) * $space;
	$lon2 += ($lon2-$lon1) * $space;

	//color handling
	$color_intvl = ($time_max - $time_min) / count($c);

	//date range
	$date_min = gmdate('Y-m-d H:i:s', $time_min);
	$date_max = gmdate('Y-m-d H:i:s', $time_max);

	//get the data!
	$points = array();
	$deviation = array();
	$sql = "SELECT id, time, lat, lon, deviation, polarity
			FROM ".BO_DB_PREF."strikes
			USE INDEX (time)
			WHERE 1
				".($radius ? "AND distance < $radius" : "")."
				".($only_own ? " AND part>0 " : "")."
				AND NOT (lat < $lat1 OR lat > $lat2 OR lon < $lon1 OR lon > $lon2)
				AND time BETWEEN '$date_min' AND '$date_max'
			-- ORDER BY time ASC";
	$erg = bo_db($sql);
	
	//Max. strikes per tile
	$num = $erg->num_rows;
	$max = intval(BO_MAP_MAX_STRIKES_PER_TILE);
	
	while ($row = $erg->fetch_assoc())
	{
		//Max. strikes per tile handling
		//This random thing is quick&easy but needs no further strike calculation (position/time/color)
		//Problem: tile borders
		if ($max && $num > $max)
		{
			if (rand(0, $num) > $max)
				continue;
		}
		
		list($px, $py) = bo_latlon2tile($row['lat'], $row['lon'], $zoom);

		if ($zoom >= $zoom_show_deviation)
		{
			 list($dlat, $dlon) = bo_distbearing2latlong($row['deviation'], 0, $row['lat'], $row['lon']);
			 list($dx, $dy)     = bo_latlon2tile($dlat, $dlon, $zoom);
			 $deviation[]		= $py - $dy;
		}

		$px -= (BO_TILE_SIZE * $x);
		$py -= (BO_TILE_SIZE * $y);

		$strike_time = strtotime($row['time'].' UTC');
		$col = floor(($time_max - $strike_time) / $color_intvl);
		
		$points[] = array($px, $py, $col, $row['polarity']);
	}
	
	BoDb::close();

	//no points --> blank tile
	if (count($points) == 0)
	{
		bo_tile_output($file, $caching);
	}


	//create Image
	$I = imagecreate(BO_TILE_SIZE, BO_TILE_SIZE);
	$blank = imagecolorallocate($I, 0, 0, 0);
	$white = imagecolorallocate($I, 255, 255, 255);
	imagefilledrectangle( $I, 0, 0, BO_TILE_SIZE, BO_TILE_SIZE, $blank);

	foreach($c as $i => $rgb)
		$color[$i] = imagecolorallocate($I, $rgb[0], $rgb[1], $rgb[2]);

	
	if ($zoom >= BO_MAP_STRIKE_SHOW_CIRCLE_ZOOM) //circle (grows with zoom)
	{
		$s = floor((BO_MAP_STRIKE_CIRCLE_SIZE+BO_MAP_STRIKE_CIRCLE_GROW*$zoom)/2)*2-1;
	}
	else if ($zoom >= BO_EXPERIMENTAL_POLARITY_ZOOM && BO_EXPERIMENTAL_POLARITY_CHECK === true)
	{
		$s = BO_MAP_STRIKE_POLARITY_SIZE;
		$style = 2; //with polarity
	}
	else
	{
		$s = BO_MAP_STRIKE_SIZE;
		$style = 1;
	}

	foreach($points as $i => $p)
	{
		switch($style)
		{
			case 1: // plot a "+"
				
				imagesetthickness($I, 2);
				imageline($I, $p[0]-$s, $p[1], $p[0]+$s-1, $p[1], $color[$p[2]]);
				imageline($I, $p[0], $p[1]-$s, $p[0], $p[1]+$s-1, $color[$p[2]]);
				break;

			case 2:
				if ($p[3] == null) //plot circle (no polarity known)
				{
					imagesetthickness($I, 1);
					imagefilledellipse($I, $p[0], $p[1], $s, $s, $color[$p[2]]);
				}
				else //plot "+" or "-"
				{
					$t = $s - 2;
					imagesetthickness($I, 2);
					imageline($I, $p[0]-$t, $p[1], $p[0]+$t-1, $p[1], $color[$p[2]]);
					if ($p[3] > 0)
						imageline($I, $p[0], $p[1]-$t, $p[0], $p[1]+$t-1, $color[$p[2]]);
				}

				break;

			default: // plot circle
				imagesetthickness($I, 1);
				imagefilledellipse($I, $p[0], $p[1], $s, $s, $color[$p[2]]);

				if ($p[3] != null && BO_EXPERIMENTAL_POLARITY_CHECK == true)
				{
					$t = intval($s / 2);
					imageline($I, $p[0]-$t+1, $p[1], $p[0]+$t-1, $p[1], $white);
					if ($p[3] > 0)
						imageline($I, $p[0], $p[1]-$t+1, $p[0], $p[1]+$t-1, $white);
				}

				break;
		}

		if ($zoom >= $zoom_show_deviation)
		{
			imagesetthickness($I, 1);
			imageellipse($I, $p[0], $p[1], $deviation[$i], $deviation[$i], $color[$p[2]]);
		}
	}

	imagecolortransparent($I, $blank);
	bo_tile_output($file, $caching, $I);
}

function bo_tile_tracks()
{
	global $_BO;
	session_write_close();

	if (!intval(BO_TRACKS_SCANTIME)) //disabled
		exit;
	
	if (rand(0, 3000) == 1)
	{
		register_shutdown_function('bo_delete_files', BO_DIR.'cache/tiles/', 24, 3);
	}
	
	$caching = !(defined('BO_CACHE_DISABLE') && BO_CACHE_DISABLE === true);
	$x = intval($_GET['x']);
	$y = intval($_GET['y']);
	$zoom = intval($_GET['zoom']);

	$file = BO_DIR.'cache/tiles/tracks_'.$zoom.'_'.$x.'x'.$y.'.png';
	
	if (BO_CACHE_SUBDIRS === true)
		$file = strtr($file, array('_' => '/'));

	if (file_exists($file) && $caching)
	{
		if (file_exists($file) && filemtime($file) + intval(BO_UP_INTVL_TRACKS) > time())
		{
			header("Content-Type: image/png");
			readfile($file);
			exit;
		}
	}
	
	//create Image
	$I = imagecreate(BO_TILE_SIZE, BO_TILE_SIZE);
	$blank = imagecolorallocatealpha($I, 255, 255, 255, 127);
	imagefill($I, 0, 0, $blank);

	imagesavealpha($I, true);
	imagealphablending($I, false);
	
	

	if ($zoom >= BO_TRACKS_MAP_ZOOM_MIN && $zoom <= BO_TRACKS_MAP_ZOOM_MAX)
	{
		$data = unserialize(gzinflate(bo_get_conf('strike_cells')));
		
		if (is_array($data['cells']))
		{
			$size = 10 + (pow(1.5,$zoom-BO_TRACKS_MAP_ZOOM_MIN+8));
			
			if ($size >= 67)
			{
				$rsizex = 30;
				$rsizey = 27;
				$textsize = 3;
				$size = 70;
			}
			else
			{
				$rsizex = 20;
				$rsizey = 17;
				$textsize = 1;
			}
			
			$linecolor = imagecolorallocatealpha($I, 50, 150, 50, 0 );
			$textcolor = imagecolorallocatealpha($I, 0, 0, 0, 0 );
			$rectcolorfill = imagecolorallocatealpha($I, 230, 230, 230, 10 );
			$rectcolorline = imagecolorallocatealpha($I, 50, 100, 50, 0 );

			//forecast style
			$col1 = imagecolorallocatealpha($I, 0, 155, 0, 127);
			$col2 = imagecolorallocatealpha($I, 0, 155, 0, 0);
			$style = array($col1, $col1, $col1, $col1, $col2, $col2, $col2, $col2);
			imagesetstyle($I, $style);
			$forecastcolorfill = imagecolorallocatealpha($I, 160, 255, 160, 20 );
			
			$count = count($data['cells']) - 1;
			for ($i=0; $i<=$count; $i++)
				$color[$i] = imagecolorallocatealpha($I, 
															100 - 50*($i/$count), 
															255, 
															250 - 150*($i/$count), 
															0);
		
			foreach($data['cells'] as $i => $cells)
			{
				if ($i == 0 && BO_TRACKS_SHOW_UNTRACKED === false)
					continue;
				
				$time_range = $data['cells_time'][$i]['end'] - $data['cells_time'][$i]['start'];

				
				foreach($data['cells'][$i] as $cellid => $cell)
				{
					if (!isset($cell['dist']) && BO_TRACKS_SHOW_UNTRACKED === false)
						continue;
					
					if ($cell['count'] < intval(BO_TRACKS_MAP_MIN_STRIKES_DISPLAY))
						continue;
					
					list($px, $py) = bo_latlon2tile($cell['lat'], $cell['lon'], $zoom);
					$px -= (BO_TILE_SIZE * $x);
					$py -= (BO_TILE_SIZE * $y);
					
					if ($px/BO_TILE_SIZE > 4 || $px/BO_TILE_SIZE < -4 || $py/BO_TILE_SIZE > 4 || $py/BO_TILE_SIZE < -4)
						continue;
					
					$circle_drawn = false;
					
					if (isset($cell['dist']))
					{
						foreach($cell['dist'] as $did => $dist)
						{
							//old cell
							$old = $cell['old'][$did];
							$oldcount = $data['cells'][$i-1][$old]['count'];
							
							if ($oldcount < intval(BO_TRACKS_MAP_MIN_STRIKES_DISPLAY))
								continue 2;

							//$distance to specified time range
							$dist = $cell['dist'][$did] / $time_range * 60 * BO_TRACKS_MAP_TIME_FORCAST;
							
							list($lat, $lon) = bo_distbearing2latlong($dist, $cell['bear'][$did], $cell['lat'], $cell['lon']);
							list($px2, $py2) = bo_latlon2tile($lat, $lon, $zoom);
							$px2 -= (BO_TILE_SIZE * $x);
							$py2 -= (BO_TILE_SIZE * $y);

							imageline($I, $px, $py, $px2, $py2, $linecolor);
							imagefilledellipse($I, $px2, $py2, $size/1.2, $size/1.2, $forecastcolorfill);
							imageellipse($I, $px2, $py2, $size/1.2, $size/1.2, IMG_COLOR_STYLED);
							
							
							
							//show info data (speed...)
							if ($zoom >= BO_TRACKS_MAP_ZOOM_INFO)
							{
								imagestring($I, $textsize, $px2-8, $py2-8, '+'.intval(BO_TRACKS_MAP_TIME_FORCAST), $textcolor);
								imagestring($I, $textsize, $px2-8, $py2+2, 'min', $textcolor);
								
								$strikechange = round(($cell['count'] - $oldcount) / $oldcount * 100);
								if ($strikechange > 0)
									$strikechange = '+'.$strikechange;
								
								$strikepermin = number_format($cell['count'] / $time_range * 60, 1, _BL('.'), _BL(','));

								//speed
								$speed = $cell['dist'][$did] / $time_range * 3.6;
								
								//Position
								$pxr = $px;
								$pyr = $py;
								
								//imagefilledrectangle($I, $pxr - $rsizex, $pyr - $rsizey, $pxr + $rsizex, $pyr + $rsizey, $rectcolorfill);
								//imagerectangle($I, $pxr - $rsizex, $pyr - $rsizey, $pxr + $rsizex, $pyr + $rsizey, $rectcolorline);
								
								imagefilledellipse($I, $px, $py, $size, $size, $color[$i]);
								imageellipse($I, $px, $py, $size+1, $size+1, $linecolor);
								$circle_drawn = true;
								
								$height = imagefontheight($textsize)+1;
								
								$pxr += 2;
								$pyr += 6;
								
								//Speed
								imagestring($I, $textsize, $pxr - $rsizex, $pyr - $rsizey, round($speed).'km/h', $textcolor);
								$pyr += $height;
								
								//Strikes
								//Doesn't make too much sense, because the scantime isn't displayed
								//imagestring($I, $textsize, $pxr - $rsizex, $pyr - $rsizey, $cell['count'], $textcolor);
								//$pyr += $height;
								
								//Strikes per minute
								imagestring($I, $textsize, $pxr - $rsizex, $pyr - $rsizey, $strikepermin.'/min', $textcolor);
								$pyr += $height;
								
								//Strike count change
								imagestring($I, $textsize, $pxr - $rsizex, $pyr - $rsizey, $strikechange.'%', $textcolor);
							}
							
							break; //currently only the first dataset
						}
					}

					if (!$circle_drawn)
					{
						imagefilledellipse($I, $px, $py, $size, $size, $color[$i]);
						imageellipse($I, $px, $py, $size+1, $size+1, $linecolor);
					}
				}
			}
		}
	}
	
	bo_tile_output($file, $caching, $I);
}

function bo_tile_output($file='', $caching=false, &$I=null)
{

	if ($caching && BO_CACHE_SUBDIRS === true)
	{
		$dir = dirname($file);
		if (!file_exists($dir))
			mkdir($dir, 0777, true);
	}


	if ($I === null)
	{
		$img = file_get_contents(BO_DIR.'images/blank_tile.png');
		$ok = @file_put_contents($file, $img);
		
		if (!$ok && $caching)
			bo_image_cache_error(BO_TILE_SIZE, BO_TILE_SIZE);
		
		header("Content-Type: image/png");
		echo $img;
		exit;
	}
		
	header("Content-Type: image/png");
	if ($caching)
	{
		$ok = @imagepng($I, $file);
		
		if (!$ok)
			bo_image_cache_error(BO_TILE_SIZE, BO_TILE_SIZE);
		
		readfile($file);
	}
	else
		imagepng($I);
		
	imagedestroy($I);

	exit;
}

//render a map with strike positions and strike-bar-plot
function bo_get_map_image()
{
	session_write_close();
	@set_time_limit(10);
	$caching = !(defined('BO_CACHE_DISABLE') && BO_CACHE_DISABLE === true);
	
	if (rand(0, 500) == 1)
	{	
		register_shutdown_function('bo_delete_files', BO_DIR.'cache/maps/', 96, 3);
	}
	
	global $_BO;

	$id 			= intval($_GET['map']);
	$date 			= $_GET['date'];
	$transparent 	= isset($_GET['transparent']);
	$blank 			= isset($_GET['blank']);
	$region			= $_GET['mark'];
	$strike_id		= intval($_GET['strike_id']);
	
	$cfg = $_BO['mapimg'][$id];
	if (!is_array($cfg))
		exit;

	$last_update = bo_get_conf('uptime_strikes');
	
	//Cache file naming
	$cache_file = BO_DIR.'cache/maps/';
	$cache_file .= _BL().'_';
	
	if (BO_CACHE_SUBDIRS === true)
		$cache_file .= $id.'/';
	
	if ($transparent)
		$cache_file .= 'transp_';

	if ($blank)
		$cache_file .= 'blank_';
	
	if ($strike_id)
		$cache_file .= 's'.$strike_id.'_';
	
	if (preg_match('/[0-9a-z]+/i', $region) && isset($_BO['region'][$region]['rect_add']))
		$cache_file .= 'region'.$region.'_';

	$archive_maps_enabled = (BO_DISABLE_ARCHIVE !== true && defined('BO_ENABLE_ARCHIVE_MAPS') && BO_ENABLE_ARCHIVE_MAPS)
								|| (bo_user_get_level() & BO_PERM_ARCHIVE);

	$sql_where = '';
	$sql_index = 'time';
	
	if ($strike_id)
	{
		if (!$archive_maps_enabled)
			exit('Forbidden!');
		
		$time_min = 0;
		$time_max = time();
		
		$sql_where .= " AND id='$strike_id' ";
		
		$cfg['legend'] = array();
		
		$sql_index = 'PRIMARY';
	}
	else if (preg_match('/^[0-9\-]+$/', $date))
	{
		
		if (!$archive_maps_enabled)
			exit('Forbidden!');
		
		$year = substr($date, 0, 4);
		$month = substr($date, 4, 2);
		$day = substr($date, 6, 2);

		$hour = substr($date, 8, 2);
		$minute = substr($date, 10, 2);
		$duration = intval(substr($date, 13));

		if (!bo_user_get_level())
		{
			if ( ($duration > 60 * 24 || ($duration && $duration < 60)) )
				exit;
			
			//allow only specific settings for guests
			$minute   = floor($minute / 15) * 15;
			$duration = floor($duration / 15) * 15;
		}
		
		if ($duration)
		{
			$time_min = strtotime("$year-$month-$day $hour:$minute:00");
			$time_max = strtotime("$year-$month-$day $hour:$minute:00 +$duration minutes");
		}
		else
		{
			$time_min = strtotime("$year-$month-$day 00:00:00");
			$time_max = strtotime("$year-$month-$day 23:59:59");
		}
		
		
		
		if ($time_max > $last_update)
		{
			$time_max = $last_update;
		}
		else
		{
			$last_update = $time_max + 3600;
		}

		$time_string = date(_BL('_date').' ', $time_min);
		$time_string .= date('H:i', $time_min).' - '.date('H:i', $time_max);
		
		$expire = time() + 3600;
		
		if (BO_CACHE_SUBDIRS === true)
			$cache_file .= date('dmY', $time_min).'/';
		
		$cache_file .= $id.'_'.$date.'.png';
		
	}
	else
	{
		$expire = $last_update + 60 * BO_UP_INTVL_STRIKES + 10;
		$time = time();
		$time_min = $time - 3600 * $cfg['trange'];
		$time_max = $time;
		
		//$time_string  = date(_BL('_date').' ', $time_min);
		$time_string .= date('H:i', $time_min).' - '.date('H:i', $time_max);
		
		$cache_file .= $id.'.png';
	}

	$date_min = gmdate('Y-m-d H:i:s', $time_min);
	$date_max = gmdate('Y-m-d H:i:s', $time_max);
	
	
	header("Pragma: ");
	header("Last-Modified: ".gmdate("D, d M Y H:i:s", $last_update)." GMT");
	header("Expires: ".gmdate("D, d M Y H:i:s", $expire)." GMT");
	header("Cache-Control: public, max-age=".($expire - time()));
	header("Content-Disposition: inline; filename=\"MyBlitzortungStrikeMap.png\"");

	//Caching
	if ($caching && file_exists($cache_file) && filemtime($cache_file) >= $last_update)
	{
		header("Content-Type: image/png");
		readfile($cache_file);
		exit;
	}

	$file = $cfg['file'];
	$latN = $cfg['coord'][0];
	$lonE = $cfg['coord'][1];
	$latS = $cfg['coord'][2];
	$lonW = $cfg['coord'][3];
	$c = $cfg['col'];
	$size = $cfg['point_size'];

	if ($cfg['dim'][0] && $cfg['dim'][1])
	{
		$w = $cfg['dim'][0];
		$h = $cfg['dim'][1];
		$I = imagecreate($w, $h);
	}

	if ($file)
	{
		if ($transparent)
		{
			if (!$w || !$h)
				list($w, $h) = getimagesize(BO_DIR.'images/'.$file);
			
			$I = imagecreate($w, $h);
			$blank = imagecolorallocate($I, 0, 0, 0);
			imagefilledrectangle( $I, 0, 0, $w, $h, $blank);
			imagecolortransparent($I, $blank);
		}
		else if ($w && $h)
		{
			$J = imagecreatefrompng(BO_DIR.'images/'.$file);
			imagecopy($I, $J, 0, 0, 0, 0, imagesx($J), imagesy($J));
			imagedestroy($J);
		}
		else
		{
			$I = imagecreatefrompng(BO_DIR.'images/'.$file);
			$w = imagesx($I);
			$h = imagesy($I);
		}
	}

	if (!$transparent && BO_IMAGE_USE_TRUECOLOR === true) //to truecolor
	{
		$tmpImage = imagecreatetruecolor($w, $h);
		imagecopy($tmpImage,$I,0,0,0,0,$w,$h);
		imagedestroy($I);
		$I = $tmpImage;
		imagealphablending($I, true);
	}

	list($x1, $y1) = bo_latlon2mercator($latS, $lonW);
	list($x2, $y2) = bo_latlon2mercator($latN, $lonE);
	$w_x = $w / ($x2 - $x1);
	$h_y = $h / ($y2 - $y1);

	foreach($c as $i => $rgb)
	{
		$color[$i] = imagecolorallocate($I, $rgb[0], $rgb[1], $rgb[2]);
		$count[$i] = 0;
	}
	
	if ($cfg['col_smooth'])
	{
		for ($i=0;$i<=$cfg['col_smooth'];$i++)
		{
			list($red, $green, $blue, $alpha) = bo_value2color($i/$cfg['col_smooth'], $c);
			$color_smooth[$i] = imagecolorallocatealpha($I, $red, $green, $blue, $alpha);
		}
	
	}
	
	//for backward compat.
	if (!isset($cfg['point_style']) && $cfg['point_type'])
		$cfg['point_style'] = array(0 => $cfg['point_type'], 1 => $cfg['point_size']);

	$time_range  = $time_max - $time_min;
	$color_intvl = $time_range / count($c);
	
	if (!$blank)
	{
		$sql = "SELECT time, lat, lon
				FROM ".BO_DB_PREF."strikes
				USE INDEX ($sql_index)
				WHERE 1
					".($only_own ? " AND part>0 " : "")."
					AND NOT (lat < '$latS' OR lat > '$latN' OR lon < '$lonW' OR lon > '$lonE')
					AND time BETWEEN '$date_min' AND '$date_max'
					$sql_where
					".bo_region2sql($region)."
				-- ORDER BY time ASC";
		$erg = bo_db($sql);
		while ($row = $erg->fetch_assoc())
		{
			$strike_time = strtotime($row['time'].' UTC');
			$age = $time_max-1 - $strike_time;
			$color_index = floor($age / $color_intvl);
			$count[$color_index]++;

			if (isset($cfg['point_style']))
			{
				list($px, $py) = bo_latlon2mercator($row['lat'], $row['lon']);
				$x =      ($px - $x1) * $w_x;
				$y = $h - ($py - $y1) * $h_y;


				if ($cfg['col_smooth'])
					$pcolor = $color_smooth[floor($age / $time_range * $cfg['col_smooth'])];
				else
					$pcolor = $color[$color_index];
				
				bo_drawpoint($I, $x, $y, $cfg['point_style'], $pcolor);
			}
		}
	}
	
	//default color
	$text_col = imagecolorallocate($I, $cfg['textcolor'][0], $cfg['textcolor'][1], $cfg['textcolor'][2]);

	if (!$transparent)
	{
		//Borders
		if ($cfg['borders'][0] && file_exists(BO_DIR.'images/'.$cfg['borders'][0]))
		{
			$tmpImage = imagecreatefrompng(BO_DIR.'images/'.$cfg['borders'][0]);
			if ($tmpImage)
				imagecopymerge($I, $tmpImage, 0,0, 0,0, $w, $h, $cfg['borders'][1]);
		}
		
		
		//add cities
		bo_add_cities2image($I, $cfg, $w, $h);
		
		//add stations
		bo_add_stations2image($I, $cfg, $w, $h, $strike_id);
		
		//Show station pos
		if ($cfg['show_station'][0])
		{
			$stinfo = bo_station_info();
			
			list($px, $py) = bo_latlon2mercator($stinfo['lat'], $stinfo['lon']);
			$x =      ($px - $x1) * $w_x;
			$y = $h - ($py - $y1) * $h_y;
			
			$size = $cfg['show_station'][0];
			
			if (isset($cfg['show_station'][1]))
				$stat_color = imagecolorallocate($I, $cfg['show_station'][1],$cfg['show_station'][2],$cfg['show_station'][3]);
			else
				$stat_color = $text_col;
				
			imageline($I, $x-$size, $y, $x+$size, $y, $stat_color);
			imageline($I, $x, $y-$size, $x, $y+$size, $stat_color);
			
			if ($cfg['show_station'][4])
			{
				$tsize = (int)$cfg['show_station'][4];
				$tsize = $tsize > 4 ? $tsize : 9;
				
				$dx = isset($cfg['show_station'][6]) ? (int)$cfg['show_station'][6] : 2;
				$dy = isset($cfg['show_station'][7]) ? (int)$cfg['show_station'][7] : -12;
				
				bo_imagestring($I, $tsize, $x+$dx, $y+$dy, $stinfo['city'], $stat_color, $cfg['show_station'][5]);
			}
		}
	}
	
	//Show Regions (for developing)
	if ($region && isset($_BO['region'][$region]['rect_add']))
	{
		$rect_col['rect_add'] = imagecolorallocate($I, 0, 255, 0);
		$rect_col['rect_rem'] = imagecolorallocate($I, 255, 100, 0);
		
		foreach(array('rect_add', 'rect_rem') as $rect_type)
		{
			$reg = $_BO['region'][$region][$rect_type];
			
			while ($r = @each($reg))
			{
				$lat1 = $r[1];
				list(,$lon1) = @each($reg);
				list(,$lat2) = @each($reg);
				list(,$lon2) = @each($reg);
				
				list($px, $py) = bo_latlon2mercator($lat1, $lon1);
				$rx1 =      ($px - $x1) * $w_x;
				$ry1 = $h - ($py - $y1) * $h_y;

				list($px, $py) = bo_latlon2mercator($lat2, $lon2);
				$rx2 =      ($px - $x1) * $w_x;
				$ry2 = $h - ($py - $y1) * $h_y;
				
				imagerectangle($I, $rx1, $ry1, $rx2, $ry2, $rect_col[$rect_type]);
			}
		}
	}
	
	
	
	if (!$blank)
	{
		/* LEGEND */
		//lightning legend
		if (isset($cfg['legend']) && is_array($cfg['legend']) && count($cfg['legend']))
		{
			$fontsize = $cfg['legend'][0];
			$cw = $cfg['legend'][1];
			$ch = $cfg['legend'][2];
			$cx = $cfg['legend'][3];
			$cy = $cfg['legend'][4];

			$coLegendWidth = floor($cw / count($color));
			$cx = $w - $cw - $cx;
			$cy = $h - $ch - $cy;
			$legend = true;
		}
		
		//banners
		$extra = _BL('Strikes', true).': '.array_sum($count);
		bo_image_banner_top($I, $w, $h, $cfg, $time_string, $extra);
		bo_image_banner_bottom($I, $w, $h, $cfg, $cw);


		if ($legend)
		{
			$legend_text_drawn = false;

			ksort($count);
			
			foreach($count as $i => $cnt)
			{
				if (max($count))
					$height = $ch * $cnt / max($count);
				else
					$height = 0;

				$px1 = $cx + (count($color)-$i-1) * $coLegendWidth;
				$px2 = $cx + (count($color)-$i) * $coLegendWidth - 1;
				$py1 = $cy + $ch;
				$py2 = $cy + $ch - $height;

				imagefilledrectangle($I, $px1, $py1, $px2, $py2, $color[$i]);

				if (!$legend_text_drawn && $cfg['legend'][0] &&
						(    ($transparent  && $i == count($color)-1)
						  || (!$transparent && $cnt == max($count))
						) 
				   )
				{
				
					if (isset($cfg['legend_font']))
					{
						$fontsize = $cfg['legend_font'][0];
						$tbold = $cfg['legend_font'][1];
						$tcol = $cfg['legend_font'][2];
						$ldx = $cfg['legend_font'][3];
						$ldy = $cfg['legend_font'][4];
					}
					else
						$ldx = -5;
				
					bo_imagestring($I, $fontsize, $px1+$coLegendWidth/2-$fontsize/2+$ldx, $py1 - 4+$ldy, $cnt, $tcol, $tbold, 90);
					$legend_text_drawn = true;
				}

			}

			if ($cfg['legend'][5])
			{
				imagesetthickness($I, 1);
				imageline($I, $cx-1, $cy-1, $cx-1, $cy+$ch, $text_col);
				imageline($I, $cx-1, $cy+$ch, $cx+$cw+2, $cy+$ch, $text_col);
			}
		}

	}
	

	BoDb::close();
	
	bo_image_reduce_colors($I);
	
	header("Content-Type: image/png");
	if ($caching)
	{
		if (BO_CACHE_SUBDIRS === true)
		{
			$dir = dirname($cache_file);
			if (!file_exists($dir))
				mkdir($dir, 0777, true);
		}
		
		$ok = @imagepng($I, $cache_file);
		
		if (!$ok)
			bo_image_cache_error($w, $h);
		
		readfile($cache_file);
	}
	else
		imagepng($I);

	exit;
}


//render a map with strike positions and strike-bar-plot
function bo_get_density_image()
{
	$densities_enabled = defined('BO_CALC_DENSITIES') && BO_CALC_DENSITIES
							&& ((defined('BO_ENABLE_DENSITIES') && BO_ENABLE_DENSITIES) || (bo_user_get_level() & BO_PERM_ARCHIVE))
							&& BO_DISABLE_ARCHIVE !== true;
	
	if (!$densities_enabled)
		exit('Forbidden');

	if (rand(0, 50) == 1)
	{
		if (BO_CACHE_SUBDIRS === true)
			register_shutdown_function('bo_delete_files', BO_DIR.'cache/densitymap', 0, 3);
		else
			register_shutdown_function('bo_delete_files', BO_DIR.'cache', 0, 0);
	}
		
	$year = intval($_GET['bo_year']);
	$month = intval($_GET['bo_month']);
	$map_id = intval($_GET['map']);
	$station_id = intval($_GET['id']);
	$ratio = isset($_GET['ratio']) && $station_id;
	
	
	@set_time_limit(30);
	
	global $_BO;

	//Image settings
	$cfg = $_BO['mapimg'][$map_id];
	if (!is_array($cfg) || !$cfg['density'])
		exit('Missing image data!');

	$min_block_size = max($cfg['density_blocksize'], intval($_GET['bo_blocksize']), 1);	
		
	//todo: needs adjustments
	$last_update = strtotime('today +  4 hours');
	$expire      = strtotime('today + 28 hours');
	
	header("Pragma: ");
	header("Last-Modified: ".gmdate("D, d M Y H:i:s", $last_update)." GMT");
	header("Expires: ".gmdate("D, d M Y H:i:s", $expire)." GMT");
	header("Cache-Control: public, max-age=".($expire - time()));
	header("Content-Disposition: inline; filename=\"MyBlitzortungDensity.png\"");

	//Caching
	$caching = !(defined('BO_CACHE_DISABLE') && BO_CACHE_DISABLE === true);
	$cache_file = BO_DIR.'cache/';
	
	if (BO_CACHE_SUBDIRS === true)
		$cache_file .= 'densitymap/'.$map_id.'/';
	else
		$cache_file .= 'densitymap_'.$map_id.'_';
		
	$cache_file .= _BL().'_'.sprintf('station%d_%d_b%d_%04d%02d.png', $station_id, $ratio ? 1 : 0, $min_block_size, $year, $month);
	
	
	
	if ($caching && file_exists($cache_file) && filemtime($cache_file) >= $last_update)
	{
		header("Content-Type: image/png");
		readfile($cache_file);
		exit;
	}
		
	$file = $cfg['file'];
	$PicLatN = $cfg['coord'][0];
	$PicLonE = $cfg['coord'][1];
	$PicLatS = $cfg['coord'][2];
	$PicLonW = $cfg['coord'][3];
	$colors = is_array($cfg['density_colors']) ? $cfg['density_colors'] : $_BO['tpl_density_colors'];

	$tmpImage = imagecreatefrompng(BO_DIR.'images/'.$file);
	$w = imagesx($tmpImage);
	$h = imagesy($tmpImage);

	//Legend
	$LegendWidth = 150;
	$ColorBarWidth  = 10;
	$ColorBarHeight = $h - 70;
	$ColorBarX = $w + 10;
	$ColorBarY = 50;
	$ColorBarStep = 15;
	
	//create new image
	$I = imagecreatetruecolor($w+$LegendWidth, $h);
	imagecopy($I,$tmpImage,0,0,0,0,$w,$h);
	imagedestroy($tmpImage);
	imagealphablending($I, true);
	$text_col = imagecolorallocate($I, $cfg['textcolor'][0], $cfg['textcolor'][1], $cfg['textcolor'][2]);
	$fontsize = $w / 100;	
	
	if ($cfg['density_darken'])
	{
		$color = imagecolorallocatealpha($I, 0, 0, 0, (1 - $cfg['density_darken'] / 100) * 127);
		imagefilledrectangle($I, 0,0, $w, $h, $color);
	}

	//Legend
	$color = imagecolorallocatealpha($I, 100, 100, 100, 0);
	imagefilledrectangle($I, $w, 0, $w+$LegendWidth, $h, $color);
	
	list($x1, $y1) = bo_latlon2mercator($PicLatS, $PicLonW);
	list($x2, $y2) = bo_latlon2mercator($PicLatN, $PicLonE);
	$w_x = $w / ($x2 - $x1);
	$h_y = $h / ($y2 - $y1);

	if ($month)
	{
		$date_start = "$year-$month-01";
		$date_end   = date('Y-m-d', mktime(0,0,0,$month+1,0,$year));
	}
	else
	{
		$date_start = "$year-01-01";
		$date_end   = "$year-12-31";	
	}
	
	//find density to image
	$sql = "SELECT 	id, station_id, type, info, data, 
					lat_max, lon_max, lat_min, lon_min, length,
					date_start, date_end, status
					FROM ".BO_DB_PREF."densities 
					WHERE 1 
						AND status >= 1 
						AND date_start = '$date_start'
						AND date_end   <= '$date_end'
						AND 
							(station_id = $station_id
							".($ratio ? " OR station_id=0 " : "")."
							)
						AND lat_max >= '$PicLatN'
						AND lon_max >= '$PicLonE'
						AND lat_min <= '$PicLatS'
						AND lon_min <= '$PicLonW'
					ORDER BY length ASC, date_end DESC, station_id ASC
					LIMIT 2
						";
	$res = bo_db($sql);
	$row = $res->fetch_assoc();

	
	$exit_msg = '';
	if (!$row['id'])
		$exit_msg = _BL('No data available!', true);
	
	//Data and info
	$DATA = gzinflate($row['data']);
	$info = unserialize($row['info']);
	$bps = $info['bps'];
	$type = $row['type'];
	$max_real_count = $info['max']; //max strike count for area elements
	
	//dates 
	$date_start = $row['date_start'];
	$date_end = $row['date_end'];
	$time_string = date(_BL('_date'), strtotime($row['date_start'])).' - '.date(_BL('_date'), strtotime($row['date_end']));
	
	//coordinates
	$DensLat       = $row['lat_min'];
	$DensLon       = $row['lon_min'];
	$DensLat_end   = $row['lat_max'];
	$DensLon_end   = $row['lon_max'];
	$length    = $row['length'];
	$area      = pow($length, 2);
	$distance = $length * sqrt(2) * 1000;
	
	if ($ratio)
	{
		$row_own = $res->fetch_assoc();
		if ($station_id != $row_own['station_id'] || $date_end != $row_own['date_end'] || $type != $row_own['type'])
		{
			$exit_msg = _BL('Not enough data available!', true);	
		}
		else
		{
			$OWN_DATA = gzinflate($row_own['data']);
			$info = unserialize($row_own['info']);
			$max_real_own_count = $info['max']; //max strike count
		}
	}
	
	unset($row['data']);
	unset($row_own['data']);

	if ($station_id)
	{
		$stinfo = bo_station_info($station_id);
		list($px, $py) = bo_latlon2mercator($stinfo['lat'], $stinfo['lon']);
		$StX =      ($px - $x1) * $w_x;
		$StY = $h - ($py - $y1) * $h_y;
	}
	
	// Exit if not enough data
	if ($exit_msg)
	{
		$fw = imagefontwidth($fontsize) * strlen($exit_msg);
		imagestring($I, $fontsize, $w/2 - $fw/2 - 1, $h / 2, $exit_msg, $text_col);
		header("Content-Type: image/png");
		imagepng($I);
		exit;
	}
	
	//pointer on current part of string
	$string_pos = 0;
	
	$STRIKE_COUNT = array();
	$VAL_COUNT = array();
	$strike_count = 0;
	$strike_count_own = 0;
	$max_count_block = 0;
	$max_count_pos = 0;
	$last_y = $h;
	
	BoDb::close();
	
	while ($DensLat < $DensLat_end)
	{
		
		//density: difference to current lat/lon
		list($dlat, $dlon) = bo_distbearing2latlong($distance, 45, $DensLat, $DensLon);
		$dlat -= $DensLat;
		$dlon -= $DensLon;
		
		// check if latitude lies in picture
		if ($DensLat + $dlat >= $PicLatS)
		{
			//select correct data segment from data string
			$lon_start_pos  = floor(($PicLonW-$DensLon)/$dlon) * 2 * $bps;
			$lon_string_len = floor(($PicLonE-$DensLon)/$dlon) * 2 * $bps - $lon_start_pos;
		
			$lon_data = substr($DATA, $string_pos + $lon_start_pos, $lon_string_len);
			
			//image coordinates (left side of image, height is current latitude)
			list($px, $py) = bo_latlon2mercator($DensLat, $PicLonE);
			$y  = $h - ($py - $y1) * $h_y; //image y
			$ay = round(($y / $min_block_size)); //block number y
			$dx = $dlon / ($PicLonE - $PicLonW) * $w; //delta x
			
			if ($ratio)
			{
				$lon_data_own = substr($OWN_DATA, $string_pos + $lon_start_pos, $lon_string_len);
			}
			
			//get the data!
			for($j=0; $j<$lon_string_len/2/$bps; $j++)
			{
				//image x
				$x = $j * $dx;
				
				//x coordinates to picture "block-numbers"
				$ax = round(($x / $min_block_size));
				$pos_id = $ax+$ay*$w;

				//number of calculated values in block
				if (!$ratio)
				{
					//sum up for density, because strike count is an absolute value and we need the mean value of a block
					$VAL_COUNT[$pos_id]++;
				}

				//strikes per square kilometer
				$value = hexdec(substr($lon_data, $j * 2 * $bps, 2 * $bps));
				
				if (!intval($value))
					continue;

				$strike_count += $value;
					
				if ($ratio)
				{
					//sum up here, so $value == 0 doesn't affect the calculation (ratio is a relative value)
					$VAL_COUNT[$pos_id]++;
					$own_value = hexdec(substr($lon_data_own, $j * 2 * $bps, 2 * $bps));
					$strike_count_own += $own_value;
					$value = $own_value / $value;
				}

				//Save to Data array
				$STRIKE_COUNT[$pos_id] += $value;
			}
		}

		$string_pos += (floor(($DensLon_end-$DensLon)/$dlon)+2) * 2 * $bps;
		
		// stop if picture is full
		if ($DensLat > $PicLatN)
			break;
			
		$DensLat += $dlat;
	}

	if ($ratio)
	{
		$max_count_block = 1; //always 100%
	}
	else
	{
		//find max strikes per block
		foreach($STRIKE_COUNT as $pos_id => $value)
		{
			if ($STRIKE_COUNT[$pos_id]/$VAL_COUNT[$pos_id] > $max_count_block)
			{
				$max_count_pos = $pos_id;
				$max_count_block = $STRIKE_COUNT[$pos_id]/$VAL_COUNT[$pos_id];
			}
		}
	}
	
	if ($max_count_block)
	{
		foreach($STRIKE_COUNT as $pos_id => $value)
		{
			$x = ($pos_id % $w);
			$y = ($pos_id-$x) / $w;

			//mean value of a block
			$value /= $VAL_COUNT[$pos_id];
			
			if (!$ratio)
			{
				//strike count to relative count 0 to 1 for colors
				$value /= $max_count_block;
			}
			
			$x *= $min_block_size;
			$y *= $min_block_size;
			
			list($red, $green, $blue, $alpha) = bo_value2color($value, $colors);
			$color = imagecolorallocatealpha($I, $red, $green, $blue, $alpha);
			imagefilledrectangle($I, $x, $y, $x+$min_block_size-1, $y+$min_block_size-1, $color);

		}
	}

	//Borders
	if ($cfg['borders'][0] && file_exists(BO_DIR.'images/'.$cfg['borders'][0]))
	{
		$tmpImage = imagecreatefrompng(BO_DIR.'images/'.$cfg['borders'][0]);
		if ($tmpImage)
			imagecopymerge($I, $tmpImage, 0,0, 0,0, $w, $h, $cfg['borders'][1]);
	}
	
	//add cities
	bo_add_cities2image($I, $cfg, $w, $h);

	//Antennas
	if ($ratio && $station_id == bo_station_id() && isset($info['antennas']) && is_array($info['antennas']['bearing']))
	{
		$col1 = imagecolorallocatealpha($I, 255, 255, 255, 127);
		$col2 = imagecolorallocatealpha($I, 255, 255, 255, 30);
		$style = array($col1, $col1, $col1, $col1, $col2, $col2, $col2, $col2);
		imagesetstyle($I, $style);
		
		$size = 0.3 * ($w + $h) / 2;
		
		foreach($info['antennas']['bearing'] as $bear)
		{
			list($lat, $lon) = bo_distbearing2latlong(100000, $bear, $stinfo['lat'], $stinfo['lon']);
			list($px, $py) = bo_latlon2mercator($lat, $lon);
			$ant_x =      ($px - $x1) * $w_x - $StX;
			$ant_y = $h - ($py - $y1) * $h_y - $StY;
			
			$ant_xn = $ant_x / sqrt(pow($ant_x,2) + pow($ant_y,2)) * $size;
			$ant_yn = $ant_y / sqrt(pow($ant_x,2) + pow($ant_y,2)) * $size;
			
			imageline($I, $StX, $StY, $StX + $ant_xn *  1, $StY + $ant_yn *  1, IMG_COLOR_STYLED);
			imageline($I, $StX, $StY, $StX + $ant_xn * -1, $StY + $ant_yn * -1, IMG_COLOR_STYLED);
		}
	}

	
	//Legend (again!)
	$color = imagecolorallocatealpha($I, 100, 100, 100, 0);
	imagefilledrectangle($I, $w, 0, $w+$LegendWidth, $h, $color);

	//Legend: Text
	$PosX = $w + 5;
	$PosY = 10;
	$MarginX = 8;

	//Station name
	if ($station_id)
	{
		$PosY = bo_imagestring_max($I, 2, $PosX, $PosY, _BL('Station', true).':', $text_col, $LegendWidth);
		$PosY = bo_imagestring_max($I, 3, $PosX+$MarginX, $PosY, $stinfo['city'], $text_col, $LegendWidth);
		$PosY += 10;
	}
	
	//Strike count
	$PosY = bo_imagestring_max($I, 2, $PosX, $PosY, _BL('Strikes', true).':', $text_col, $LegendWidth);
	$PosY = bo_imagestring_max($I, 3, $PosX+$MarginX, $PosY, $strike_count, $text_col, $LegendWidth);
	$PosY += 10;
	
	
	if ($ratio && intval($strike_count))
	{
		$PosY = bo_imagestring_max($I, 2, $PosX, $PosY, strtr(_BL('densities_strikes_station', true), array('{STATION_CITY}' => $stinfo['city'])).':', $text_col, $LegendWidth);
		$PosY = bo_imagestring_max($I, 3, $PosX+$MarginX, $PosY, $strike_count_own, $text_col, $LegendWidth);
		$PosY += 10;
	
		$PosY = bo_imagestring_max($I, 2, $PosX, $PosY, _BL('Mean strike ratio', true).':', $text_col, $LegendWidth);
		$PosY = bo_imagestring_max($I, 3, $PosX+$MarginX, $PosY, number_format($strike_count_own / $strike_count * 100, 1, _BL('.'), _BL(',')).'%', $text_col, $LegendWidth);
		$PosY += 25;
	}
	else
		$PosY += 15;
	
	/*
	//Area elements (calculation)
	$length_text = number_format($length, 1, _BL('.'), _BL(','));
	$PosY = bo_imagestring_max($I, 2, $PosX, $PosY, _BL("Calculation basis are elements with area", true).':', $text_col, $LegendWidth);
	$PosY = bo_imagestring_max($I, 3, $PosX+$MarginX, $PosY, " ".$length_text.'km x '.$length_text.'km', $text_col, $LegendWidth);
	$PosY += 10;

	if (!$ratio && $area)
	{
		$max_real_density = $max_real_count / $area;
		
		//Strike density
		$PosY = bo_imagestring_max($I, 2, $PosX, $PosY, _BL('Maximum strike density calculated', true).':', $text_col, $LegendWidth);
		$PosY = bo_imagestring_max($I, 3, $PosX+$MarginX, $PosY, " ".number_format($max_real_density, 1, _BL('.'), _BL(',')).'/km^2', $text_col, $LegendWidth);
		$PosY += 10;
	}
	
	$PosY += 15;
	*/
	
	if (!$ratio)
	{
		//Max. density per block
		$max_density = $max_count_block / $area;
		$PosY = bo_imagestring_max($I, 2, $PosX, $PosY, _BL('Maximum mean strike density displayed', true).':', $text_col, $LegendWidth);
		$PosY = bo_imagestring_max($I, 3, $PosX+$MarginX, $PosY, number_format($max_density, 3, _BL('.'), _BL(',')).'/km^2', $text_col, $LegendWidth);
		$PosY += 15;
	}
	
	$PosY += 10;
	$PosY = bo_imagestring_max($I, 5, $PosX, $PosY, _BL('Legend', true), $text_col, $LegendWidth);
	if ($ratio)
		$PosY = bo_imagestring_max($I, 2, $PosX+$MarginX, $PosY, '('._BL('Strike ratio', true).')', $text_col, $LegendWidth);
	else
		$PosY = bo_imagestring_max($I, 2, $PosX+$MarginX, $PosY, '('._BL('Strikes per square kilometer', true).')', $text_col, $LegendWidth);
	
	if ($PosY + 15 > $ColorBarY)
	{
		$ColorBarHeight -= $PosY+15 - $ColorBarY;
		$ColorBarY = $PosY+15;
	}
	
	//Legend: Colorbar
	for ($i=$ColorBarY; $i<= $ColorBarHeight+$ColorBarY; $i += $ColorBarStep)
	{
		$value = 1-($i-$ColorBarY)/$ColorBarHeight;
		
		list($red, $green, $blue, $alpha) = bo_value2color($value, $colors);
		$color = imagecolorallocatealpha($I, $red, $green, $blue, $alpha);	
		imagefilledrectangle($I, $ColorBarX, $i, $ColorBarX+$ColorBarWidth, $i+$ColorBarStep-1, $color);
	}
	
	//Legend: Colorbar Text
	if ($ratio)
	{
		$max_ratio = $max_count_block;
		$text_top = number_format($max_ratio*100, 1, _BL('.'), _BL(',')).'%';
		$text_middle = '50%';
		$text_bottom = '0%';
	}
	else
	{
		$max_density = $max_count_block / $area;
		$text_top = number_format($max_density, 3, _BL('.'), _BL(','));
		$text_middle = number_format($max_density/2, 3, _BL('.'), _BL(','));
		$text_bottom = '0';
	}
	
	imagestring($I, 3, $ColorBarX+$ColorBarWidth+6, $ColorBarY+3, $text_top, $text_col);
	imagestring($I, 3, $ColorBarX+$ColorBarWidth+6, $ColorBarY-8+$ColorBarHeight/2, $text_middle, $text_col);
	imagestring($I, 3, $ColorBarX+$ColorBarWidth+6, $ColorBarY-5+$ColorBarHeight, $text_bottom, $text_col);
	
	
	//Station Name
	$extra_text = '';
	if ($station_id)
	{
		imagestring($I, $fontsize, 1, 1 + $fontsize * 3, $text, $text_col);
		
		$size = 6;
		$color = imagecolorallocate($I, 255,255,255);
		imageline($I, $StX-$size, $StY, $StX+$size, $StY, $color);
		imageline($I, $StX, $StY-$size, $StX, $StY+$size, $color);
		
	}

	//Banner
	$extra_text = _BL($ratio ? 'Strike ratio' : 'Strike density', true);

	bo_image_banner_top($I, $w, $h, $cfg, $time_string, $extra_text);
	bo_image_banner_bottom($I, $w, $h, $cfg, 0);
	
	


	bo_image_reduce_colors($I, true);

	header("Content-Type: image/png");
	if ($caching)
	{
		if (BO_CACHE_SUBDIRS === true)
		{
			$dir = dirname($cache_file);
			if (!file_exists($dir))
				mkdir($dir, 0777, true);
		}

		$ok = @imagepng($I, $cache_file, 9, PNG_ALL_FILTERS);
		
		if (!$ok)
			bo_image_cache_error($w, $h);

		readfile($cache_file);
	}
	else
		imagepng($I, null, 9, PNG_ALL_FILTERS);

	exit;
	
}


function bo_image_banner_top($I, $w, $h, $cfg, $time_string = null, $extra = null, $copy = true)
{
	//default color
	$text_col = imagecolorallocate($I, $cfg['textcolor'][0], $cfg['textcolor'][1], $cfg['textcolor'][2]);

	$tdy = 0;
	if (isset($cfg['top_style']))
	{
		imagefilledrectangle($I, 0,0, $w-1, $cfg['top_style'][0], bo_hex2color($I, $cfg['top_style'][2]));
		$tdy = $cfg['top_style'][1];
		
		if ($cfg['top_style'][3])
		{
			imagesetthickness($I, $cfg['top_style'][3]);
			imageline($I, 0,$cfg['top_style'][0], $w,$cfg['top_style'][0], bo_hex2color($I, $cfg['top_style'][4]));
		}
	}
	
	if (isset($cfg['top_font']))
	{
		$fontsize = $cfg['top_font'][0];
		$tbold = $cfg['top_font'][1];
		$tcol = $cfg['top_font'][2];
	}
	else //for old template style
	{
		$fontsize = $cfg['textsize'] ? $cfg['textsize'] : $w / 80;
		$tbold = true;
		$tcol = $text_col;
	}
	
	//Date/Time/Strikes
	if ($time_string !== null)
		bo_imagestring($I, $fontsize, 2, 2+$tdy, $time_string, $tcol, $tbold);

	//Strikes
	if ($extra !== null)
		bo_imagestringright($I, $fontsize, $w - 2, 2+$tdy, $extra, $tcol, $tbold);
	
	//Own Copyright
	if (defined('BO_OWN_COPYRIGHT') && $copy)
	{
		$copy_width = bo_imagetextwidth($fontsize, $tbold, BO_OWN_COPYRIGHT);
		$info_text_width = bo_imagetextwidth($fontsize, $tbold, $time_string.'         '.$strike_text);
		
		if ($w - $info_text_width > $copy_width)
		{
			$copy_pos = $w / 2 - $copy_width / 2;
			bo_imagestring($I, $fontsize, $copy_pos, 2+$tdy, BO_OWN_COPYRIGHT, $tcol, $tbold);
		}
	}
}


function bo_image_banner_bottom($I, $w, $h, $cfg, $legend_width = 0, $copy = false)
{
	//default color
	$text_col = imagecolorallocate($I, $cfg['textcolor'][0], $cfg['textcolor'][1], $cfg['textcolor'][2]);

	$tdy = 0;
	
	if (isset($cfg['top_font']))
	{
		$fontsize = $cfg['top_font'][0];
		$tbold = $cfg['top_font'][1];
		$tcol = $cfg['top_font'][2];
	}
	else //for old template style
	{
		$fontsize = $cfg['textsize'] ? $cfg['textsize'] : $w / 80;
		$tbold = true;
		$tcol = $text_col;
	}

	if (isset($cfg['bottom_font']))
	{
		$fontsize = $cfg['bottom_font'][0];
		$tbold = $cfg['bottom_font'][1];
		$tcol = $cfg['bottom_font'][2];
	}
	

	/* BOTTOM LINE */
	if (isset($cfg['bottom_style']))
	{
		imagefilledrectangle($I, 0,$h, $w, $h-$cfg['bottom_style'][0], bo_hex2color($I, $cfg['bottom_style'][2]));
		$tdy = $cfg['bottom_style'][1];
		
		if ($cfg['bottom_style'][3])
		{
			imagesetthickness($I, $cfg['bottom_style'][3]);
			imageline($I, 0,$h-$cfg['bottom_style'][0], $w,$h-$cfg['bottom_style'][0], bo_hex2color($I, $cfg['bottom_style'][4]));
		}
	}
	

	$tdy = bo_imagetextheight($fontsize);		
	
	//Copyright
	$text = _BL('Lightning data from Blitzortung.org', true);
	$bo_width = bo_imagetextwidth($fontsize, $tbold, $text);
	if ($bo_width > $w - $legend_width - 5)
		$text = _BL('Blitzortung.org', true);
	bo_imagestring($I, $fontsize, 4, $h - $tdy, $text, $tcol, $tbold);

	//Own copyright
	if (defined('BO_OWN_COPYRIGHT') && $copy)
	{
		$bo_width2 = bo_imagetextwidth($fontsize, $tbold, BO_OWN_COPYRIGHT);
		$bo_pos2 = $bo_width + $fontsize * 5;
		
		if ($bo_width2+$bo_pos2 < $w - $legend_width - 5)
			bo_imagestring($I, $fontsize, $bo_pos2, $h - $tdy, BO_OWN_COPYRIGHT, $tcol, $tbold);
	}
}

//writes text with automatic line brakes into an image
function bo_imagestring_max(&$I, $size, $x, $y, $text, $textcol, $maxwidth)
{
	$text = strtr($text, array(chr(160) => ' '));
	
	$line_height = imagefontheight($size) * 1.2;
	$breaks = explode("\n", $text);
	
	foreach($breaks as $text2)
	{
		$width = 0;
		$lines = explode(" ", $text2);
		$fw = imagefontwidth($size);
		$x2 = $x;
		
		foreach($lines as $i=>$line)
		{
			$width = $fw*(strlen($line)+1);
			
			if ($x2+$width+3 > $x+$maxwidth)
			{
				$y += $line_height;
				$x2 = $x;
			}
			
			imagestring($I, $size, $x2, $y, $line, $textcol);
			
			$x2 += $width;
		}
		
		$y += $line_height;
	}
	return $y;
}

//error output
function bo_image_error($w, $h, $text, $size=2)
{
	$I = imagecreate($w, $h);
	imagefill($I, 0, 0, imagecolorallocate($I, 255, 150, 150));
	$black = imagecolorallocate($I, 0, 0, 0);
	bo_imagestring($I, $size, 10, $h/2-25, $text, $black, $w-20);
	imagerectangle($I, 0,0,$w-1,$h-1,$black);
	
	Header("Content-type: image/png");
	Imagepng($I);
	exit;
}

function bo_image_cache_error($w, $h)
{
	bo_image_error($w, $h, 'Creating image failed! Please check if your cache-dirs are writeable!', 3);
}

//get an image from /images directory
//we need this for easy integration of MyBlitzortung in other projects
function bo_get_image($img)
{
	switch($img)
	{
		case 'bt':
			$file = 'blank_tile.png';
			break;

		case 'logo':
			$file = 'blitzortung_logo.jpg';
			break;

		case 'wait':
			$file = 'wait.gif';
			break;
		
		default: //default image
		case 'my':
			$file = 'myblitzortung.png';
			break;
		
	}

	if (preg_match('/^flag_([a-zA-Z]{2})$/', $img, $r))
	{
		$file = 'flags/'.$r[1].'.png';
	}

	$ext = strtr(substr($file, -3), array('jpg' => 'jpeg'));

	$file = BO_DIR.'images/'.$file;

	if (!file_exists($file))
		exit;
	
	$mod_time = filemtime($file);
	$exp_time = time() + 3600 * 24 * 7;
	$age      = $exp_time - time();

	header("Content-Type: image/".$ext);
	header("Pragma: ");
	header("Last-Modified: ".gmdate("D, d M Y H:i:s", $mod_time)." GMT");
	header("Expires: ".gmdate("D, d M Y H:i:s", $exp_time)." GMT");
	header("Cache-Control: public, max-age=".$age);

	readfile($file);
	exit;
}

// for density images: value (from 0 to 1) to color
function bo_value2color($value, &$colors)
{
	$color_count = count($colors)-1;
	
	if ($value > 1) //this shouldn't happen!
	{
		$red = $green = $blue = 255;
		$alpha = 0;
	}
	else if ($value == 1)
	{
		$red   = $colors[$color_count][0];
		$green = $colors[$color_count][1];
		$blue  = $colors[$color_count][2];
		$alpha = $colors[$color_count][3];
	}
	else
	{
		$color_index = floor($value * ($color_count));
		$color_pos   = $value * ($color_count) - floor($value * ($color_count)); //find "position" between the two colors
		
		$col1 = $colors[$color_index];
		$col2 = $colors[$color_index+1];
		
		$red   = $col1[0] + ($col2[0] - $col1[0]) * $color_pos;
		$green = $col1[1] + ($col2[1] - $col1[1]) * $color_pos;
		$blue  = $col1[2] + ($col2[2] - $col1[2]) * $color_pos;
		$alpha = $col1[3] + ($col2[3] - $col1[3]) * $color_pos;
	}
	
	return array($red, $green, $blue, $alpha);
}


function bo_image_reduce_colors(&$I, $density_map=false)
{
	if ($density_map)
		$colors = intval(BO_IMAGE_PALETTE_COLORS_DENSITIES);
	else
		$colors = intval(BO_IMAGE_PALETTE_COLORS_MAPS);
	
	
	if ($colors)
	{
		//works only for palette images
		$total = imagecolorstotal($I);
		if ($total && $total <= 256)
			return;

		
		if ($colors)
		{
			$auto = true;
			$width = imagesx($I);
			$height = imagesy($I);
			
			if (BO_IMAGE_PALETTE_AUTO)
			{
				$Itmp = ImageCreateTrueColor($width, $height);
				ImageCopy($Itmp, $I, 0, 0, 0, 0, $width, $height);
			}
			
			//reduce colors: imagecolormatch doesn't exist in some PHP-GD modules (i.e. Ubuntu)
			if (function_exists('imagecolormatch'))
			{
				$colors_handle = ImageCreateTrueColor($width, $height);
				ImageCopyMerge($colors_handle, $I, 0, 0, 0, 0, $width, $height, 100 );
				ImageTrueColorToPalette($I, false, $colors);
				ImageColorMatch($colors_handle, $I);
				ImageDestroy($colors_handle);
			}
			else
			{
				imagetruecolortopalette($I, false, $colors);
			}
			
			if (BO_IMAGE_PALETTE_AUTO)
			{
				if (imagecolorstotal($I) == 256) //too much colors ==> back to truecolor
				{
					imagedestroy($I);
					$I = $Itmp;
				}
				else
				{
					imagedestroy($Itmp);
				}
			}
			
		}
	}
}


function bo_add_cities2image($I, $cfg, $w, $h)
{
	if (!isset($cfg['cities']) || !is_array($cfg['cities']))
		return;
	
	$sql_types = '';
	foreach($cfg['cities'] as $type => $data)
	{
		if (!$data['point'][0])
			continue;
		
		$sql_types .= " OR type='$type' ";
	}
	
	$latN = $cfg['coord'][0];
	$lonE = $cfg['coord'][1];
	$latS = $cfg['coord'][2];
	$lonW = $cfg['coord'][3];
	
	list($x1, $y1) = bo_latlon2mercator($latS, $lonW);
	list($x2, $y2) = bo_latlon2mercator($latN, $lonE);
	$w_x = $w / ($x2 - $x1);
	$h_y = $h / ($y2 - $y1);

	$sql = "SELECT id, name, lat, lon, type
			FROM ".BO_DB_PREF."cities
			WHERE 1
				AND NOT (lat < '$latS' OR lat > '$latN' OR lon < '$lonW' OR lon > '$lonE')
				AND (0 $sql_types)
			ORDER BY type ASC";
	$erg = bo_db($sql);
	while ($row = $erg->fetch_assoc())
	{
		list($px, $py) = bo_latlon2mercator($row['lat'], $row['lon']);
		$x =      ($px - $x1) * $w_x;
		$y = $h - ($py - $y1) * $h_y;

		$c = $cfg['cities'][$row['type']];
	
		if ($c['font'][0])
		{
			if ($c['font'][3] < 0)
				$font_x = $x - bo_imagetextwidth($c['font'][3], $c['font'][0], $c['font'][1]) + $c['font'][3];
			else
				$font_x = $x + $c['font'][3];

			$font_y = $y + $c['font'][4];
		
			bo_imagestring($I, $c['font'][0], $font_x, $font_y, $row['name'], $c['font'][2], $c['font'][1]);
		}
		
		bo_drawpoint($I, $x, $y, $c['point']);	
	
	}
	
}


function bo_add_stations2image($I, $cfg, $w, $h, $strike_id = 0)
{
	global $_BO;
	
	if (!$strike_id && (!isset($cfg['stations']) || empty($cfg['stations'])))
		return;
	
	$latN = $cfg['coord'][0];
	$lonE = $cfg['coord'][1];
	$latS = $cfg['coord'][2];
	$lonW = $cfg['coord'][3];
	
	list($x1, $y1) = bo_latlon2mercator($latS, $lonW);
	list($x2, $y2) = bo_latlon2mercator($latN, $lonE);
	$w_x = $w / ($x2 - $x1);
	$h_y = $h / ($y2 - $y1);

	$stations = bo_stations();

	if ($strike_id)
	{
	
		$sql = "SELECT lat, lon
				FROM ".BO_DB_PREF."strikes
				WHERE id='$strike_id'";
		$erg = bo_db($sql);
		$row = $erg->fetch_assoc();
		list($px, $py) = bo_latlon2mercator($row['lat'], $row['lon']);
		$strike_x =      ($px - $x1) * $w_x;
		$strike_y = $h - ($py - $y1) * $h_y;
	
		$sql = "SELECT ss.station_id id
				FROM ".BO_DB_PREF."stations_strikes ss
				WHERE ss.strike_id='$strike_id'
				";
		$erg = bo_db($sql);
		while ($row = $erg->fetch_assoc())
		{
			$stations[$row['id']]['part'] = 1;
		}
		
		$tmp = $cfg['stations'][0];
		unset($cfg['stations']);
		if (0 && !is_array($tmp))
			$cfg['stations'][0] = $tmp;
		else
			$cfg['stations'][0] = $_BO['points'][BO_ARCHIVE_STR_DETAILS_DEFAULT_POINT];
	}
	
	foreach($stations as $id => $d)
	{
		$type = $d['status'];
		$lon = $d['lon'];
		$lat = $d['lat'];
		
		if ( (!isset($cfg['stations'][$type]) && !isset($cfg['stations'][0]) )
				|| $lat > $latN || $lat < $latS || $lon > $lonE || $lon < $lonW)
			continue;
		
		if (isset($cfg['stations'][$type]))
			$c = $cfg['stations'][$type];
		else
			$c = $cfg['stations'][0];
		
		list($px, $py) = bo_latlon2mercator(round($d['lat'],2), round($d['lon'],2));
		$x =      ($px - $x1) * $w_x;
		$y = $h - ($py - $y1) * $h_y;

		if ($c['font'][0])
		{
			if ($c['font'][3] < 0)
				$font_x = $x - bo_imagetextwidth($c['font'][3], $c['font'][0], $c['font'][1]) + $c['font'][3];
			else
				$font_x = $x + $c['font'][3];

			$font_y = $y + $c['font'][4];
		
			bo_imagestring($I, $c['font'][0], $font_x, $font_y, $d['city'], $c['font'][2], $c['font'][1]);
		}
		
		bo_drawpoint($I, $x, $y, $c['point']);
		
		if ($strike_id && $d['part'])
		{
			imageline($I, $strike_x, $strike_y, $x, $y, bo_hex2color($I, BO_ARCHIVE_STR_DETAILS_LINECOLOR));
		}
	
	}
	
}


function bo_imagestring(&$I, $size, $x, $y, $text, $tcolor = false, $bold = false, $angle = 0, $bordercolor = false, $px = 0)
{
	$font = bo_imagestring_font($size, $bold);
	
	if (is_string($tcolor))
	{
		$color = bo_hex2color($I, $tcolor);
	}
	elseif (is_array($tcolor))
	{
		$color = bo_hex2color($I, $tcolor[0]);
		$bordercolor = bo_hex2color($I, $tcolor[2]);
		$px = $tcolor[1];
	}
	else
		$color = $tcolor;

	if ($size <= 5)
	{
		if ($angle == 90)
			imagestringup($I, $size, $x, $y, $text, $color);
		else
			imagestring($I, $size, $x, $y, $text, $color);
	}
	else
	{
		$h = $angle ? 0 : $size;
		$w = $angle ? $size : 0;
		
		$text = utf8_encode($text);
		
		bo_imagettftextborder($I, $size, $angle, $x+$w, $y+$h, $color, $font, $text, $bordercolor, $px);
	}
}

function bo_imagestringright($I, $size, $x, $y, $text, $color = false, $bold = false, $angle = 0)
{
	$x -= bo_imagetextwidth($size, $bold, $text);
	return bo_imagestring($I, $size, $x, $y, $text, $color, $bold);
}


function bo_imagetextheight($size, $bold = false, $string = false)
{
	if ($size <= 5)
	{
		return imagefontheight($size);
	}
	
	$font = bo_imagestring_font($size, $bold);

	$string = $string === false ? 'Ag' : $string;
	$tmp = imagettfbbox($size, 0, $font, $string);
	$height = $tmp[1] - $tmp[5];
	
	return $height;
}

function bo_imagetextwidth($size, $bold = false, $string = false)
{
	if ($size <= 5)
	{
		return imagefontwidth($size) * strlen($string);
	}
	
	$font = bo_imagestring_font($size, $bold);
	
	$string = $string === false ? 'A' : $string;
	$tmp = imagettfbbox($size, 0, $font, $string);
	$width = $tmp[2] - $tmp[0];
	
	return $width;
}


function bo_imagestring_font(&$size, &$type)
{
	if ($type === true) // bold
		$font = BO_DIR.BO_FONT_TTF_BOLD;
	else if ((int)$type && $type == 1)
		$font = BO_DIR.BO_FONT_TTF_MONO;
	else
		$font = BO_DIR.BO_FONT_TTF_NORMAL;

	
	return $font;

}


function bo_imagettftextborder(&$I, $size, $angle, $x, $y, &$textcolor, $font, $text, $bordercolor = false, $px = 0)
{
	if ($px)
	{
		for($c1 = ($x-abs($px)); $c1 <= ($x+abs($px)); $c1++)
			for($c2 = ($y-abs($px)); $c2 <= ($y+abs($px)); $c2++)
				$bg = imagettftext($I, $size, $angle, $c1, $c2, $bordercolor, $font, $text);
	}
 
   return imagettftext($I, $size, $angle, $x, $y, $textcolor, $font, $text);
}

function bo_hex2color(&$I, $str)
{
	$rgb = bo_hex2rgb($str);

	if (count($rgb) == 4 && imageistruecolor($I))
		return imagecolorallocatealpha($I, $rgb[0], $rgb[1], $rgb[2], $rgb[3]);
	else
		return imagecolorallocate($I, $rgb[0], $rgb[1], $rgb[2]);
}

function bo_hex2rgb($str) 
{
    $hexStr = preg_replace("/[^0-9A-Fa-f]/", '', $str);
    $rgb = array();
	
	if (strlen($hexStr) == 3 || strlen($hexStr) == 4) 
	{
        $rgb[0] = hexdec(str_repeat(substr($hexStr, 0, 1), 2));
        $rgb[1] = hexdec(str_repeat(substr($hexStr, 1, 1), 2));
        $rgb[2] = hexdec(str_repeat(substr($hexStr, 2, 1), 2));
		
		if (strlen($hexStr) == 4)
			$rgb[3] = hexdec(str_repeat(substr($hexStr, 3, 1), 2)) / 2;
    } 
	elseif (strlen($hexStr) == 6 || strlen($hexStr) == 8) 
	{
        $rgb[0] = hexdec(substr($hexStr, 0, 2));
        $rgb[1] = hexdec(substr($hexStr, 2, 2));
        $rgb[2] = hexdec(substr($hexStr, 4, 2));
		
		if (strlen($hexStr) == 8)
			$rgb[3] = hexdec(substr($hexStr, 6, 2)) / 2;
    }
	
    return $rgb;
}

function bo_drawpoint($I, $x, $y, $style, $color = null, $strikedata = null)
{
	if ($color == null && $style[2]) //fillcolor
		$color = bo_hex2color($I, $style[2]);

	$bordercolor = null;
		
	if ($style[3]) 
	{
		$bordercolor = bo_hex2color($I, $style[4]);
		imagesetthickness($I, $style[3]);
	}

	$s = $style[1]; //size
		
		
	switch ($style[0])
	{
		case 1: //Circle
			
			if ($s == 1)
			{
				imagesetpixel($I, $x, $y, $color);
			}
			else if ($s == 2)
			{
				imagerectangle($I, $x, $y, $x+1, $y+1, $color);
			}
			else
			{
				imagefilledellipse($I, $x, $y, $s, $s, $color);
			}
			
			if ($bordercolor !== null)
				imageellipse($I, $x, $y, $s+1, $s+1, $bordercolor);
				
			break;
		
		
		case 2: //Plus
		
			$s /= 2;
			$x = (int)$x;
			$y = (int)$y;
			
			if ($bordercolor !== null)
			{
				imagesetthickness($I, $style[3]+2);
				imageline($I, $x-$s-1, $y, $x+$s+1, $y, $bordercolor);
				imageline($I, $x, $y-$s-1, $x, $y+$s+1, $bordercolor);
			}
			
			if ($style[3])
				imagesetthickness($I, $style[3]);
				
			imageline($I, $x-$s, $y, $x+$s, $y, $color);
			imageline($I, $x, $y-$s, $x, $y+$s, $color);
			
	
			break;
		
		
		case 3: // Square
		
			$s /= 2;
			
			if ($style[2])
				imagefilledrectangle($I, $x-$s, $y-$s, $x+$s, $y+$s, $color);
			
			if ($bordercolor !== null)
				imagerectangle($I, $x-$s-1, $y-$s-1, $x+$s+1, $y+$s+1, $bordercolor);
				
			break;
		
		case 10: // Station sign *g*
		
			imageline($I, $x-$s*0.6, $y+$s*0.9, $x+$s*0.6, $y+$s*0.9, $color);
			imageline($I, $x, $y-$s, $x, $y+$s*0.9, $color);
			
			imagefilledellipse($I, $x, $y-$s, $s-1, $s-1, $color);
			
			imagearc($I, $x-$s, $y-$s, $s*4, $s*3, -30, +30, $bordercolor);
			imagearc($I, $x+$s, $y-$s, $s*4, $s*3, -30+180, +30+180, $bordercolor);
			
			break;


			
		case 20: // Strike sign
		
		$points = array(
					$x-$s*0.3, $y+$s*0.1, 
					$x-$s*0.1, $y+$s*0.1,
					$x-$s*0.3, $y+$s,
					$x+$s*0.4, $y-$s*0.1, 
					$x+$s*0.1, $y-$s*0.1,
					$x+$s*0.7, $y-$s, 
					$x+$s*0.1, $y-$s,
					$x-$s*0.3, $y+$s*0.1);

		if ($style[2])					
			imagefilledpolygon($I, $points, count($points)/2, $color);
		
		if ($bordercolor !== null)
			imagepolygon($I, $points, count($points)/2, $bordercolor);
		
			
			break;
			
		
		default:
		
			if (function_exists($style[0]))
				call_user_func($style[0], $I, $x, $y, $color, $style, $strikedata);
				
			break;
			
	}

}



?>