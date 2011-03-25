<?php

	$maxtime = (60*10);
	$coe = 0.13;
	$events = array();
	$encounters = array();
	$encounters['10n'] = "Lich King 10";
	$encounters['10h'] = "Lich King 10 Heroic";
	$encounters['25n'] = "Lich King 14";
	$encounters['25h'] = "Lich King 25 Heroic";
	
	import_request_variables("g", "g_");
	
	$g_encounter = $g_encounter ? $g_encounter : "25h";
	
	$g_plague_dispel_delay = is_numeric($g_plague_dispel_delay) ? $g_plague_dispel_delay : 4;
	if($g_plague_dispel_delay > 4) $g_plague_dispel_delay = 4;
	$g_plague_coe = $g_plague_coe ? 1 : 0;
	$g_dps_players = is_numeric($g_dps_players) ? $g_dps_players : 17;
	$g_dps_per_player = is_numeric($g_dps_per_player) ? $g_dps_per_player : 9000;
	$g_dps_on_first_horror = is_numeric($g_dps_on_first_horror) ? $g_dps_on_first_horror : 9;
	
	switch($g_encounter)
	{
		default:
		case "10n":
			$g_lichking_health = 17400000;
			$g_horror_health = 2000000;
			$g_plague_tick = 50000;
			break;
		case "10h":
			$g_lichking_health = 29500000;
			$g_horror_health = 3000000;
			$g_plague_tick = 75000;
			break;
		case "25n":
			$g_lichking_health = 61300000;
			$g_horror_health = 4000000;
			$g_plague_tick = 100000;
			break;
		case "25h":
			$g_lichking_health = 103200000;
			$g_horror_health = 6000000;
			$g_plague_tick = 150000;
			break;
	}

	switch($g_encounter)
	{
		default:
		case "10n":
		case "10h":
			if($g_dps_players > 6) $g_dps_players = 6;
			if($g_dps_on_first_horror > 6) $g_dps_on_first_horror = 3;
			break;
		case "25n":
		case "25h":
			if($g_dps_players < 10) $g_dps_players = 17;
			break;
	}
		
	
	// Old, now derivative
	$g_dps_total = $g_dps_players * $g_dps_per_player;
	$g_dps_first_horror = $g_dps_on_first_horror * $g_dps_per_player;
	
	function ts($secs)
	{
		if ($secs<0) return false;
		$m = (int)($secs / 60); $s = $secs % 60;
		return sprintf("%02d:%02d", $m, $s);
	}
	
    function h($health)
	{
		if($health>1000000)
		{
			return round(($health/1000000),2).'m';
		} else if($health>1000) {
			return round(($health/1000),0).'k';
		}
		return number_format($health, 0, ".", ",");
	}
	
	function cl($name) {
		return strtolower(str_replace(" ", "_", $name));
	}
	
	$last_tick = 0;
	$last_bounce = 0;
	$last_plague = 0;
	$last_horror = 0;
	$horror_count = 0;
	$plague_count = 0;
	$plague_delay = 30;
	$plague_interval = 30;
	$plague_on_horror = 0;
	$plague_on_player = 0;
	$plague_on_tank = 0;
	$plague_on_tank_stack = 0;
	$plague_on_tank_last = 0;
	$plague_bounce_delay = 15;
	$plague_tick_delay = 5;
	$horror_death_max = 0;
	$horror_delay = 20;
	$horror_interval = 60;
	$horror_mobs = array();
	$lich_king_current_health = $g_lichking_health;
	$lich_king_threshold = ($g_lichking_health * 0.7);
	$raid_damage_to_first_horror = 0;
	$plague_damage_to_horrors = 0;
	$raid_damage_to_lich_king = 0;
	$first_horror_death = 0;
	$last_horror_death = 0;
	$lich_king_transition = 0;
	$plague_siphon_stack = 0;
	$plague_siphon_duration = 30;
	$plague_siphon_last = 0;
	$remaining_horrors = 0;
	$remaining_horror_health = 0;
	$phase_1 = 1;
	
	$eventtext = sprintf("<span class='lich_king'>Lich King (%s/%s)</span> Spawned",
					h($lich_king_current_health), h($g_lichking_health));
	$events[] = array($s, $eventtext, "phase_change");
				
	$events[] = array("0", "Phase 1 Begins", "phase_change");
	
	for($s=0; $s<=$maxtime; $s++)
	{
		// DPS
		if($horror_mobs[1]['alive'])
		{
			$horror_mobs[$hc]['health_current'] -= $g_dps_first_horror;
			$lk_dmg = ($g_dps_total-$g_dps_first_horror);
			$lich_king_current_health -= $lk_dmg;
			$raid_damage_to_first_horror += $g_dps_first_horror;
			$raid_damage_to_lich_king += $lk_dmg;
		} else if($phase_1) {
			$lich_king_current_health -= $g_dps_total;
			$raid_damage_to_lich_king += $g_dps_total;
		}
		
		// Lich King Powerup
		if($plague_siphon_stack && ($s > ($plague_siphon_last + $plague_siphon_duration)))
		{
			$eventtext = sprintf("Plague Siphon (%s) faded", $plague_siphon_stack);
			$events[] = array($s, $eventtext, "plague_siphon");
			$plague_siphon_stack = 0;
		}
			
		// Lich King Threshold
		if($phase_1 && ($lich_king_current_health <= $lich_king_threshold))
		{
			$lich_king_transition = $s;
			$phase_1 = 0;
			$events[] = array($s, "Phase 1 Ends (Lich King reaches 70% health)", "phase_change");
			
			if($horror_mobs)
			foreach($horror_mobs as $hc => $horror)
			{
				if($horror['health_current'] > 0)
				{
					$remaining_horrors++;
					$remaining_horror_health += $horror['health_current'];
					$horror['health_current'] = 0;
					$horror['alive'] = 0;
				}
			}
			
			$eventtext = sprintf("There are %s remaining horror(s) with a total of %s remaining health", $remaining_horrors, h($remaining_horror_health));
			$events[] = array($s, $eventtext);
			
		}
		
		if(!$phase_1 && $remaining_horror_health)
		{
			$remaining_horror_health -= $g_dps_total;
			if($remaining_horror_health < 0) {
				$remaining_horror_health = 0;
				$horror_death_max = $horror_count;
			}
			$eventtext = sprintf("Raid does %s damage to horror(s), %s remaining", h($g_dps_total), h($remaining_horror_health));
			$events[] = array($s, $eventtext);
			
		}
		
		if(!$phase_1 && ($horror_death_max == $horror_count))
		{
			$events[] = array($s, "Simulation Ends (phase 2, no living horrors)", "simulation_end");
			break;
		}
		
		// Phase 1 only
		if($phase_1)
		{
			// First Horror or New Horror
			if( ($last_horror == 0) && ($s >= $horror_delay) ||
				($last_horror > 0) && ($s >= ($last_horror + $horror_interval)) )
			{
				$last_horror = $s;
				$horror_count++;
				$hc = $horror_count;
				$horror_mobs[$hc] = array();
				$horror_mobs[$hc]['name'] = "Horror $hc";
				$horror_mobs[$hc]['health_current'] = $g_horror_health;
				$horror_mobs[$hc]['health_total'] = $g_horror_health;
				$horror_mobs[$hc]['alive'] = 1;
				$horror_mobs[$hc]['plague'] = 0;
				$horror_mobs[$hc]['plague_ticks'] = 0;
				
				$eventtext = sprintf("<span class='%s'>%s (%s/%s)</span> Spawned",
					cl($horror_mobs[$hc]['name']), $horror_mobs[$hc]['name'], h($horror_mobs[$hc]['health_current']), h($horror_mobs[$hc]['health_total']));
				$events[] = array($s, $eventtext, "horror_spawn");
				
				if($plague_siphon_stack)
				{
					$eventtext = sprintf("Plague Siphon is at %s stacks", $plague_siphon_stack);
					$events[] = array($s, $eventtext, "plague_siphon");
				}
			}
			
			// First Plague or New Plague
			if( ($last_plague == 0) && ($s >= $plague_delay) ||
				($last_plague > 0) && ($s >= ($last_plague + $plague_interval)) )
			{
				$last_plague = $s;
				$plague_count++;
				$plague_on_player = 1;
				
				$eventtext = sprintf("<span class='player'>Player</span> infected with a fresh plague");
				$events[] = array($s, $eventtext, "plague_spawn");
				
				if($plague_siphon_stack)
				{
					$eventtext = sprintf("Plague Siphon is at %s stacks", $plague_siphon_stack);
					$events[] = array($s, $eventtext, "plague_siphon");
				}
			}
		}
		
		// Plague Ticking
		if($plague_on_horror && ($s >= ($last_tick + $plague_tick_delay)))
		{
			$hc = $plague_on_horror;
			$tick = $g_plague_tick * $horror_mobs[$hc]['plague'];
			$overkill = 0;
			if($g_plague_coe) $tick += ($g_plague_tick * $coe);
			$plague_damage_to_horrors += $tick;
			$horror_mobs[$hc]['health_current'] -= $tick;
			$horror_mobs[$hc]['plague_ticks'] ++;
			$last_tick = $s;
			
			if($horror_mobs[$hc]['health_current'] <= 0)
			{
				$overkill = abs($horror_mobs[$hc]['health_current']);
				$horror_mobs[$hc]['health_current'] = 0;
				$horror_mobs[$hc]['alive'] = 0;
				$horror_death_max = $hc;
				$last_horror_death = $s;
				if($hc == 1) $first_horror_death = $s;
				
				$eventtext = sprintf("<span class='%s'>%s</span> dies from plague (%s stacks, %s(%s) damage)",
									cl($horror_mobs[$hc]['name']), $horror_mobs[$hc]['name'], $horror_mobs[$hc]['plague'], h($tick), h($overkill));
				$events[] = array($s, $eventtext, "horror_death");
			} else {
				$eventtext = sprintf("<span class='%s'>%s (%s/%s)</span> suffers <span class='damage'>%s</span> damage from plague (%s)",
									cl($horror_mobs[$hc]['name']), $horror_mobs[$hc]['name'], h($horror_mobs[$hc]['health_current']), h($horror_mobs[$hc]['health_total']), 
									h($tick), $horror_mobs[$hc]['plague']);
				$events[] = array($s, $eventtext, "plague_tick");
			}
		}
		
		// Plague Bouncing from Horror to Tank/Player (expires or dies)
		if($plague_on_horror)
		{
			if(($horror_mobs[$plague_on_horror]['alive']==0) || ($s >= ($last_bounce + $plague_bounce_delay))) {
				$ho = $plague_on_horror;
				$bounced = 0;
				$reason = "expiration";
				
				if($horror_mobs[$plague_on_horror]['alive']==0)
				{
					$reason = "death";
				}
				
				// Try to infect a horror
				if($horror_mobs)
				foreach($horror_mobs as $hc => $horror)
				{
					if($horror['alive'] && ($ho != $hc))
					{
						$horror_mobs[$hc]['plague'] = $horror_mobs[$ho]['plague'] + 1;
						$horror_mobs[$ho]['plague'] = 0;
						$bounced = 1;
						$plague_siphon_stack++;
						$plague_siphon_last = $s;
						$plague_on_horror = $hc;
						$last_bounce = $s; // refreshes existing stack
						$last_tick = $s; // delays existing tick
						$eventtext = sprintf("<span class='%s'>%s (%s/%s)</span> gained the plague (%s) from <span class='%s'>%s (%s/%s)</span> (%s)",
										cl($horror_mobs[$hc]['name']), $horror_mobs[$hc]['name'], h($horror_mobs[$hc]['health_current']), h($horror_mobs[$hc]['health_total']),
										$horror_mobs[$hc]['plague'],
										cl($horror_mobs[$ho]['name']), $horror_mobs[$ho]['name'], h($horror_mobs[$ho]['health_current']), h($horror_mobs[$ho]['health_total']),
										$reason);
						$events[] = array($s, $eventtext, "plague_bounce");
						break;
					}
				}
				
				// Infect the tank
				if(!$bounced)
				{
					$plague_on_tank = 1;
					$plague_on_tank_last = $s;
					$plague_on_tank_stack = $horror_mobs[$ho]['plague'] + 1;
					$plague_on_horror = 0;
					$bounced = 1;
					$plague_siphon_stack++;
					$plague_siphon_last = $s;
					$last_bounce = $s; // refreshes existing stack
					$last_tick = $s; // delays existing tick
					$horror_mobs[$ho]['plague'] = 0;
					$eventtext = sprintf("<span class='tank'>Tank</span> gained the plague (%s) from <span class='%s'>%s (%s/%s)</span> (%s)",
						$plague_on_tank_stack,
						cl($horror_mobs[$ho]['name']), $horror_mobs[$ho]['name'], h($horror_mobs[$ho]['health_current']), h($horror_mobs[$ho]['health_total']),
						$reason);
					$events[] = array($s, $eventtext, "plague_bounce");
				}
			}
		}
		
		// Plague bouncing from Tank to Horror
		if($plague_on_tank && ($s >= ($plague_on_tank_last + $g_plague_dispel_delay)))
		{
			if($plague_on_horror)
			{
				$hc = $plague_on_horror;
				$horror_mobs[$hc]['plague'] += ($plague_on_tank_stack - 1);
				$plague_on_tank = 0;
				$plague_on_tank_last = 0;
				$plague_on_tank_stack = 0;
				$plague_siphon_stack++;
				$plague_siphon_last = $s;
				$last_bounce = $s; // refreshes existing stack
				$last_tick = $s; // delays existing tick
				$eventtext = sprintf("<span>%s (%s/%s)</span> gained a stack (%s) from <span class='tank'>Tank</span> (dispel)",
					cl($horror_mobs[$hc]['name']), $horror_mobs[$hc]['name'], h($horror_mobs[$hc]['health_current']), h($horror_mobs[$hc]['health_total']), $horror_mobs[$hc]['plague']);
				$events[] = array($s, $eventtext);
			} else {
				
				// Try to infect a horror
				if($horror_mobs)
				foreach($horror_mobs as $hc => $horror)
				{
					if($horror['alive'])
					{
						$horror_mobs[$hc]['plague'] = ($plague_on_tank_stack - 1);
						$plague_on_tank = 0;
						$plague_on_tank_stack = 0;
						$plague_on_horror = $hc;
						$plague_siphon_stack++;
						$plague_siphon_last = $s;
						$last_bounce = $s; // refreshes existing stack
						$last_tick = $s; // delays existing tick
						$eventtext = sprintf("<span class='%s'>%s (%s/%s)</span> gained the plague (%s) from <span class='tank'>Tank</span> (dispel)",
							cl($horror_mobs[$hc]['name']), $horror_mobs[$hc]['name'], h($horror_mobs[$hc]['health_current']), h($horror_mobs[$hc]['health_total']), $horror_mobs[$hc]['plague']);
						$events[] = array($s, $eventtext);
						break;
					}
				}
				
				// Ditch the plague
				if($plague_on_tank)
				{
					$eventtext = sprintf("<span class='tank'>Tank</span> ditched the plague (%s) (dispel)", $plague_on_tank_stack);
					$plague_on_tank = 0;
					$plague_on_tank_stack = 0;
					$plague_siphon_stack++;
					$plague_siphon_last = $s;
					$events[] = array($s, $eventtext);
				}
			}
		}
		
		// Plague bouncing from Player to Horror
		if($plague_on_player && ($s >= ($last_plague + $g_plague_dispel_delay)))
		{
			if($plague_on_horror)
			{
				$hc = $plague_on_horror;
				$horror_mobs[$hc]['plague'] += 1;
				$plague_on_player = 0;
				$plague_siphon_stack++;
				$plague_siphon_last = $s;
				$last_bounce = $s; // refreshes existing stack
				$last_tick = $s; // delays existing tick
				$eventtext = sprintf("<span class='%s'>%s (%s/%s)</span> gained a stack (%s) from <span class='player'>Player</span> (dispel)",
					cl($horror_mobs[$hc]['name']), $horror_mobs[$hc]['name'], h($horror_mobs[$hc]['health_current']), h($horror_mobs[$hc]['health_total']), $horror_mobs[$hc]['plague']);
				$events[] = array($s, $eventtext);
			} else {
				
				// Try to infect a horror
				if($horror_mobs)
				foreach($horror_mobs as $hc => $horror)
				{
					if($horror['alive'])
					{
						$horror_mobs[$hc]['plague'] = 1;
						$plague_on_player = 0;
						$plague_on_horror = $hc;
						$plague_siphon_stack++;
						$plague_siphon_last = $s;
						$last_bounce = $s; // refreshes existing stack
						$last_tick = $s; // delays existing tick
						$eventtext = sprintf("<span class='%s'>%s (%s/%s)</span> gained the plague (%s) from <span class='player'>Player</span> (dispel)",
							cl($horror_mobs[$hc]['name']), $horror_mobs[$hc]['name'], h($horror_mobs[$hc]['health_current']), h($horror_mobs[$hc]['health_total']), $horror_mobs[$hc]['plague']);
						$events[] = array($s, $eventtext);
						break;
					}
				}
				
				// Ditch the plague
				if($plague_on_player)
				{
					$plague_on_player = 0;
					$plague_siphon_stack++;
					$plague_siphon_last = $s;
					$eventtext = sprintf("<span class='player'>Player</span> ditched the plague (1) (dispel)");
					$events[] = array($s, $eventtext);
				}
			}
		}
		
		// Max Time
		if($s == ($maxtime-1))
		{
			$lich_king_transition = $s;
			$events[] = array($s, "Simulation Ends (max execution time reached, you're doing it wrong)", "simulation_end");
			break;
		}
	}
	$total_raid_damage = $raid_damage_to_lich_king + $raid_damage_to_first_horror;

	$events[] = array("S", sprintf("Total Raid Damage (%s): %s (%s dps)", ts($s), h($total_raid_damage), h($total_raid_damage/$s)), "simulation_stats");
	$events[] = array("S", sprintf("Raid Damage to Lich King (%s): %s (%s dps)", ts($lich_king_transition), h($raid_damage_to_lich_king), h($raid_damage_to_lich_king/$lich_king_transition)), "simulation_stats");
	$events[] = array("S", sprintf("Raid Damage to First Horror: %s (%s dps)", h($raid_damage_to_first_horror), h($raid_damage_to_first_horror/($first_horror_death-$horror_delay))), "simulation_stats");
	$events[] = array("S", sprintf("Plague Damage to Horrors: %s (%s dps)", h($plague_damage_to_horrors), h($plague_damage_to_horrors/($last_horror_death-$horror_delay))), "simulation_stats");
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>
	<head>
		<title>Lich King Plague Simulator</title>
		<style>
			BODY { background: #ffffff; font-family: Helvetica; font-size: 14px; }
			#f { border: 0; margin; 0; }
			#f INPUT, #f SUBMIT { font-size: 10px; }
			#o { background: #e3ffff; border-top: 2px solid #00cccc; font-size: 12px; padding: 3px; }
			#o DIV { margin-bottom: 3px; }
			#s { background: #efefef; border-top: 2px solid #ee00ee; clear: both; padding: 0; }
			#s TABLE { width: 100%; }
			#s TABLE TR TD.time { width: 80px; }
			#s TABLE TR TD.event {  }
			#s TABLE TR TD { background: #ffffff; margin: 1px; padding: 3px; }
			#s TABLE TR.h TD { background: #ffe3ff; font-weight: bold; }
			.c { clear: left; }
			
			SPAN.horror_1 { color: #5a360b; text-decoration: underline; }
			SPAN.horror_2 { color: #392207; text-decoration: underline; }
			SPAN.horror_3 { color: #7e4400; text-decoration: underline; }
			SPAN.tank { color: #721461; text-decoration: underline; }
			SPAN.player { color: #142f72; text-decoration: underline; }
			
			#s TABLE TR.horror_spawn TD { background-color: #8cf873; font-weight: bold; }
			#s TABLE TR.horror_death TD { background-color: #f87373; font-weight: bold; }
			#s TABLE TR.phase_change TD { background-color: #738cf8; font-weight: bold; }
			#s TABLE TR.simulation_end TD { background-color: #5c5c5c; color: #ffffff; font-weight: bold; }
			#s TABLE TR.simulation_stats TD { background-color: #ab9c7b; font-weight: bold; }
		</style>
	</head>
	<body>
		<h1>Lich King Plague Simulator</h1>
		<form id="f" method="get">
			<div id="o">
				<div><b>Encounter</b> <select name="encounter"><?php foreach($encounters as $ek => $ev) { printf("<option value='%s'%s>%s</option>", $ek, ($ek==$g_encounter?" selected":""), $ev); } ?></select></div>
				<div><b>Plague</b> Dispel Delay: <input type="text" size="2" name="plague_dispel_delay" value="<?php echo($g_plague_dispel_delay); ?>"> CoE: <input type="checkbox" name="plague_coe" <?php if($g_plague_coe) echo("checked");?>></div>
				<div><b>DPS</b> Players: <input type="text" size="4" name="dps_players" value="<?php echo($g_dps_players); ?>"> Per Player: <input type="text" size="8" name="dps_per_player" value="<?php echo($g_dps_per_player); ?>"> Players On First Horror: <input type="text" size="6" name="dps_on_first_horror" value="<?php echo($g_dps_on_first_horror); ?>"></div>
				<div><input type="submit" value="Simulate!"> <input type="button" value="Defaults" onclick="location.href='?';"></div>
			</div>
		</form>
		<div id="s">
			<table>
				<tr class="h">
					<td class="time">Time</td>
					<td class="event">Event</td>
				</tr>
<?php
				if($events)
				{
					foreach($events as $e)
					{
						printf("\t\t\t\t<tr class=\"%s\">\n\t\t\t\t\t<td>%s</td>\n\t\t\t\t\t<td>%s</td>\n\t\t\t\t</tr>\n", $e[2], ($e[0]=="S" ? "STATS" : ts($e[0])), $e[1]);
					}
				}
?>
			</table>
		</div>
	</body>
</html>