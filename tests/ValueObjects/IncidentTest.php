<?php

namespace ValueObjects;

use CloudEvents\V1\CloudEvent;
use Nette\Utils\Json;
use Snoofa\StpManager\ValueObjects\Incident;
use PHPUnit\Framework\TestCase;

class IncidentTest extends TestCase
{

	public function testFromGCPEvent(): void
	{
		$description = <<<'EOT'
			# Heading
			Some documentaiton for the developers.
			# Public incident description
			<public-name>
			Snoofa is not responding
			</public-name>
			<public-start-info>
			Some incident description goes here. Even with variables, e.g. policy display name: ${policy.display_name}.
			Our vigilant robo-watchdog has noticed that this service is not responding. This is likely a temporary issue, but fear not, we are already on the case, and we will inform you further until the issue is resolved.
			</public-start-info>
			<public-end-info>
			Service back up and running.
			</public-end-info>
			# Links
			- [Link 1](https://google.com)
			- [Link 2](https://about.snoofa.com)
		EOT;

		$event = $this->prepareEvent([
			'incident' => [
				'incident_id' => 'abcd',
				'state' => 'open',
				'policy_name' => 'policy',
				'policy_user_labels' => [
					'key' => 'value',
				],
				'documentation' => [
					'content' => $description,
					'mimeType' => 'text/markdown',
				]
			]
		]);

		$incident = Incident::fromGCPEvent($event);

		$this->markTestSkipped('TODO');
	}

	public function testParseFromDocs()
	{
		$data = [
			'incident' => [
				'incident_id' => 'abcd',
				'state' => 'open',
				'policy_name' => 'policy',
				'policy_user_labels' => [
					'key' => 'value',
				],
			]
		];

		// Missing doc text
		$d1 = $data;
		$e1 = $this->prepareEvent($d1);
		$i1 = Incident::fromGCPEvent($e1);

		$this->assertSame('Default name', $i1->name);
		$this->assertSame('Policy breached.', $i1->startInfo);
		$this->assertSame('Up and running.', $i1->endInfo);

		// Doc text in place but no tags found
		$d2 = $data;
		$d2['incident']['documentation']['content'] = 'Some doc text.';
		$e2 = $this->prepareEvent($d2);
		$i2 = Incident::fromGCPEvent($e2);

		$this->assertSame('Default name', $i2->name);
		$this->assertSame('Policy breached.', $i2->startInfo);
		$this->assertSame('Up and running.', $i2->endInfo);

		// Only name (test trim)
		$d3 = $data;
		$d3['incident']['documentation']['content'] = "<public-name>\n\n\t Custom incident name \n\n</public-name>";
		$e3 = $this->prepareEvent($d3);
		$i3 = Incident::fromGCPEvent($e3);

		$this->assertSame('Custom incident name', $i3->name);
		$this->assertSame('Policy breached.', $i3->startInfo);
		$this->assertSame('Up and running.', $i3->endInfo);

		// All included (docs in markdown)
		$d4 = $data;
		$d4['incident']['documentation']['content'] =
			<<<'EOT'
			# Heading
			Some documentaiton for the developers.
			# Public incident description
			<public-name>
			Snoofa is not responding
			</public-name>
			<public-start-info>
			Our vigilant robo-watchdog has noticed that this service is not responding.
			This is likely a temporary issue, but fear not, we are already on the case, and we will inform you further until the issue is resolved.
			</public-start-info>
			<public-end-info>
			Service back up and running.
			</public-end-info>
			# Links
			- [Link 1](https://google.com)
			- [Link 2](https://about.snoofa.com)
			EOT;
		$e4 = $this->prepareEvent($d4);
		$i4 = Incident::fromGCPEvent($e4);

		$this->assertSame('Snoofa is not responding', $i4->name);
		$this->assertSame("Our vigilant robo-watchdog has noticed that this service is not responding.\nThis is likely a temporary issue, but fear not, we are already on the case, and we will inform you further until the issue is resolved.", $i4->startInfo);
		$this->assertSame('Service back up and running.', $i4->endInfo);
	}

	private function prepareEvent(array $data): CloudEvent
	{
		return new CloudEvent('id', 'test', 'type', [
			'message' => [
				'data' => base64_encode(Json::encode($data))
			]
		]);
	}
}
