<?php

/*
 * This file is part of Linfo (c) 2010 Joseph Gillotti.
 * 
 * Linfo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * Linfo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with Linfo.  If not, see <http://www.gnu.org/licenses/>.
 * 
*/


defined('IN_INFO') or exit;

/*
 * Alpha osx class
 * Differs very slightly from the FreeBSD, especially in the fact that
 * only root can access dmesg
 */

class OS_Darwin extends OS_BSD_Common{
	
	// Encapsulate these
	protected
		$settings,
		$exec,
		$error,
		$dmesg;

	// Start us off
	public function __construct($settings) {

		// Initiate parent
		parent::__construct($settings);

		// We search these folders for our commands
		$this->exec->setSearchPaths(array('/sbin', '/bin', '/usr/bin', '/usr/sbin'));
	}
	
	// This function will likely be shared among all the info classes
	public function getAll() {

		// Return everything, whilst obeying display permissions
		return array(
			'OS' => empty($this->settings['show']) ? '' : $this->getOS(), 			# done
			'Kernel' => empty($this->settings['show']) ? '' : $this->getKernel(), 		# done
			'HostName' => empty($this->settings['show']) ? '' : $this->getHostName(), 	# done
			'Mounts' => empty($this->settings['show']) ? array() : $this->getMounts(), 	# done
			'Network Devices' => empty($this->settings['show']) ? array() : $this->getNet(),# done (possibly missing nics)
			'UpTime' => empty($this->settings['show']) ? '' : $this->getUpTime(), 		# done
			'Load' => empty($this->settings['show']) ? array() : $this->getLoad(), 		# done
			'processStats' => empty($this->settings['show']['process_stats']) ? array() : $this->getProcessStats(), # lacks thread stats
			'CPU' => empty($this->settings['show']) ? array() : $this->getCPU(), 		# done
			'RAM' => empty($this->settings['show']) ? array() : $this->getRam(), 		# done
			/*
			'Devices' => empty($this->settings['show']) ? array() : $this->getDevs(), 	# todo
			'HD' => empty($this->settings['show']) ? '' : $this->getHD(), 			# todo
			'RAID' => empty($this->settings['show']) ? '' : $this->getRAID(),	 	# todo(
			'Battery' => empty($this->settings['show']) ? array(): $this->getBattery(),  	# todo
			'Temps' => empty($this->settings['show']) ? array(): $this->getTemps(), 	# TODO
			*/
		);
	}

	// Operating system
	public function getOS() {
		return 'Darwin (Mac OS X)';
	}

	// kernel version
	public function getKernel() {
		return php_uname('r');
	}

	// Hostname
	public function getHostname() {
		return php_uname('n');
	}

	// Get mounted file systems
	public function getMounts() {
		// Time?
		if (!empty($this->settings['timer']))
			$t = new LinfoTimerStart('Mounted file systems');
		
		// Get result of mount command
		try {
			$res = $this->exec->exec('mount');
		}
		catch (CallExtException $e) {
			$this->error->add('Linfo Core', 'Error running `mount` command');
			return array();
		}
		
		// Parse it
		if (preg_match_all('/(.+)\s+on\s+(.+)\s+\((\w+).*\)\n/i', $res, $m, PREG_SET_ORDER) == 0)
			return array();
		
		// Store them here
		$mounts = array();
		
		// Deal with each entry
		foreach ($m as $mount) {

			// Should we not show this?
			if (in_array($mount[1], $this->settings['hide']['storage_devices']) || in_array($mount[3], $this->settings['hide']['filesystems']))
				continue;
			
			// Get these
			$size = @disk_total_space($mount[2]);
			$free = @disk_free_space($mount[2]);
			$used = $size - $free;
			
			// Might be good, go for it
			$mounts[] = array(
				'device' => $mount[1],
				'mount' => $mount[2],
				'type' => $mount[3],
				'size' => $size ,
				'used' => $used,
				'free' => $free,
				'free_percent' => ((bool)$free != false && (bool)$size != false ? round($free / $size, 2) * 100 : false),
				'used_percent' => ((bool)$used != false && (bool)$size != false ? round($used / $size, 2) * 100 : false)
			);
		}

		// Give it
		return $mounts;
		
	}

	// Get network interfaces
	private function getNet() {
		// Time?
		if (!empty($this->settings['timer']))
			$t = new LinfoTimerStart('Network Devices');

		// Store return vals here
		$return = array();
		
		// Use netstat to get info
		try {
			$netstat = $this->exec->exec('netstat', '-nbdi');
		}
		catch(CallExtException $e) {
			$this->error->add('Linfo Core', 'Error using `netstat` to get network info');
			return $return;
		}
		
		// Initially get interfaces themselves along with numerical stats
		if (preg_match_all(
			'/^([a-z0-9*]+)\s*\w+\s+<Link\#\w+>(?:\D+|\s+\w+:\w+:\w+:\w+:\w+:\w+\s+)(\w+)\s+(\w+)\s+(\w+)\s+(\w+)\s+(\w+)\s+(\w+)\s+(\w+)\s+(\w+)\s*/m', $netstat, $netstat_match, PREG_SET_ORDER) == 0)
			return $return;



		// Try using ifconfig to get states of the network interfaces
		$statuses = array();
		try {
			// Output of ifconfig command
			$ifconfig = $this->exec->exec('ifconfig', '-a');

			// Set this to false to prevent wasted regexes
			$current_nic = false;

			// Go through each line
			foreach ((array) explode("\n", $ifconfig) as $line) {

				// Approachign new nic def
				if (preg_match('/^(\w+):/', $line, $m) == 1)
					$current_nic = $m[1];

				// Hopefully match its status
				elseif ($current_nic && preg_match('/status: (\w+)$/', $line, $m) == 1) {
					$statuses[$current_nic] = $m[1];
					$current_nic = false;
				}
			}
		}
		catch(CallExtException $e) {}


		// Save info
		foreach ($netstat_match as $net) {

			// Determine status
			switch (array_key_exists($net[1], $statuses) ? $statuses[$net[1]] : 'unknown') {

				case 'active':
					$state = 'up';
				break;
				
				case 'inactive':
					$state = 'down';
				break;

				default:
					$state = 'unknown';
				break;
			}

			// Save info
			$return[$net[1]] = array(
				
				// These came from netstat
				'recieved' => array(
					'bytes' => $net[4],
					'errors' => $net[3],
					'packets' => $net[2] 
				),
				'sent' => array(
					'bytes' => $net[7],
					'errors' =>  $net[6],
					'packets' => $net[5] 
				),

				// This came from ifconfig -a
				'state' => $state,

				// Not sure where to get his
				'type' => '?'
			);
		}

		// Return it
		return $return;
	
	}

	// Get uptime
	private function getUpTime() {
		
		// Time?
		if (!empty($this->settings['timer']))
			$t = new LinfoTimerStart('Uptime');
		
		// Use sysctl to get unix timestamp of boot. Very elegant!
		$res = $this->getSysCTL('kern.boottime');
		
		// Extract boot part of it
		if (preg_match('/^\{ sec \= (\d+).+$/', $res, $m) == 0)
			return '';
		
		// Boot unix timestamp
		$booted = $m[1];

		// Get it textual, as in days/minutes/hours/etc
		return seconds_convert(time() - $booted) . '; booted ' . date('m/d/y h:i A', $booted);
	}
	
	// Get system load
	private function getLoad() {
		
		// Time?
		if (!empty($this->settings['timer']))
			$t = new LinfoTimerStart('Load Averages');
		
		// Use uptime, since it also has load values
		try {
			$res = $this->exec->exec('uptime');
		}
		catch (CallExtException $e) {
			$this->error->add('Linfo Core', 'Error using `uptime` to get system load');
			return array();
		}

		// Parse it
		if (preg_match('/^.+load averages: ([\d\.]+) ([\d\.]+) ([\d\.]+)$/', $res, $m) == 0)
			return array();
		
		// Give
		return array(
			'now' => $m[1],
			'5min' => $m[2],
			'15min' => $m[3]
		);
	
	}
	
	// Get stats on processes
	private function getProcessStats() {
		
		// Time?
		if (!empty($this->settings['timer']))
			$t = new LinfoTimerStart('Process Stats');

		// We'll return this after stuffing it with useful info
		$result = array(
			'exists' => true, 
			'totals' => array(
				'running' => 0,
				'zombie' => 0,
				'sleeping' => 0,
				'stopped' => 0,
				'idle' => 0
			),
			'proc_total' => 0,
			'threads' => false // I'm not sure how to get this
		);

		// Use ps
		try {
			// Get it
			$ps = $this->exec->exec('ps', 'ax');

			// Match them
			preg_match_all('/^\s*\d+\s+[\w?]+\s+([A-Z])\S*\s+.+$/m', $ps, $processes, PREG_SET_ORDER);
			
			// Get total
			$result['proc_total'] = count($processes);
			
			// Go through
			foreach ($processes as $process) {
				switch ($process[1]) {
					case 'S':
					case 'I':
						$result['totals']['sleeping']++;
					break;
					case 'Z':
						$result['totals']['zombie']++;
					break;
					case 'R':
					case 'D':
						$result['totals']['running']++;
					break;
					case 'T':
						$result['totals']['stopped']++;
					break;
					case 'W':
						$result['totals']['idle']++;
					break;
				}
			}
		}
		catch (CallExtException $e) {
			$this->error->add('Linfo Core', 'Error using `ps` to get process info');
		}

		// Give
		return $result;
	}

	// Get cpus
	public function getCPU() {
		// Time?
		if (!empty($this->settings['timer']))
			$t = new LinfoTimerStart('CPUs');

		$cpus = array();
		$vendor = $this->GetSysCTL('machdep.cpu.vendor');
		$type = $this->getSysCTL('machdep.cpu.brand_string');
		$speed = $this->getSysCTL('hw.cpufrequency') / 1000000;
		$quantity = $this->getSysCTL('hw.ncpu');
		
		for ($i = 0; $i < $quantity; $i++)
			$cpus[] = array(
				'Model' => $type,
				'MHz' => $speed,
				'Vendor' => $vendor
				
			);

		return $cpus;
	}
	
	// Get ram usage
	private function getRam(){
		
		// Time?
		if (!empty($this->settings['timer']))
			$t = new LinfoTimerStart('Memory');

		$return = array();
		$return['type'] = 'Virtual';
		$return['total'] = 0;
		$return['free'] = 0;
		$return['swapTotal'] = 0;
		$return['swapFree'] = 0;
		$return['swapInfo'] = array();


		$swap = $this->GetSysCTL('vm.swapusage');
		if (preg_match('/total = ([\d\.]+)M\s+used = ([\d\.]+)M\s+free = ([\d\.]+)M/', $swap, $swap_match)) {
			list(, $swap_total, $swap_used, $swap_free) = $swap_match;
			$return['swapTotal'] = $swap_total * 1000000;
			$return['swapFree'] = $swap_free * 1000000;
		}

		

		return $return;
	
	}
	
}