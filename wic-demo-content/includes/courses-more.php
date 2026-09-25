<?php
/**
 * Five more sample courses, same rules as courses.php: general, non-clinical topics,
 * no statistics, citations, regulations, medical or nutrition advice.
 * Slides may carry 'image' (a file in assets/img/) and 'alt' (a real description).
 */

defined( 'ABSPATH' ) || exit;

$wic_demo_disclaimer = '<p class="wic-demo-note"><strong>Sample course for demonstration. Replace with agency-approved content.</strong></p>';

return array(

	/* ------------------------------------------------------------------ */
	'phone'      => array(
		'title'    => 'Phone and Email Etiquette (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. Answering calls warmly, taking a clear message, and writing emails people can act on.',
		'required' => true,
		'groups'   => array( 'staff' ),
		'due_days' => 21,
		'credit'   => array( 'Customer service', 0.5 ),
		'validity' => 0,
		'modules'  => array(
			array(
				'title'  => 'On the phone',
				'slides' => array(
					array(
						'key'     => 'ph_intro',
						'title'   => 'The call is the clinic',
						'layout'  => 'image',
						'image'   => 'phone-email.svg',
						'alt'     => 'Illustration of a mobile phone showing a message bubble beside an email envelope with a notification badge.',
						'html'    => $wic_demo_disclaimer . '<p>For many people, a phone call or an email is their first contact with the clinic. The same warmth and clarity you bring to the front desk matters just as much when nobody can see your face.</p>',
						'script'  => 'For many people, a phone call or an email is their first contact with the clinic. The same warmth and clarity you bring to the front desk matters just as much when nobody can see your face.',
						'seconds' => 35,
					),
					array(
						'key'     => 'ph_answer',
						'title'   => 'Answering a call',
						'layout'  => 'callout',
						'html'    => '<ol><li><strong>Greet and identify:</strong> the clinic name and your first name.</li><li><strong>Listen first</strong> before you start searching the system.</li><li><strong>Confirm who you are speaking to</strong> using your agency\'s procedure before discussing any details.</li><li><strong>Summarise</strong> what will happen next before you hang up.</li></ol>',
						'script'  => 'When you answer a call, greet the caller with the clinic name and your first name. Listen first. Confirm who you are speaking to using your agency\'s procedure before discussing any details. And summarise what will happen next before you hang up.',
						'seconds' => 50,
					),
					array(
						'key'     => 'ph_message',
						'title'   => 'Taking a message that works',
						'layout'  => 'text',
						'html'    => '<p>A message is only useful if the next person can act on it without calling back to ask. Select each part of a good message.</p>',
						'script'  => 'A message is only useful if the next person can act on it without calling back to ask. Select each part of a good message.',
						'seconds' => 55,
						'layers'  => array(
							array( 'label' => 'Who', 'content' => 'The caller\'s name, spelled back to them, and the best number to reach them.' ),
							array( 'label' => 'What', 'content' => 'What they need, in their words, in one or two sentences.' ),
							array( 'label' => 'When', 'content' => 'When they called and when they are free to take a call back.' ),
							array( 'label' => 'Handed to', 'content' => 'Who the message is for, and how you passed it on.' ),
						),
					),
					array(
						'key'     => 'ph_hold',
						'title'   => 'Holds and transfers',
						'layout'  => 'text',
						'html'    => '<p>Ask before you put someone on hold, and tell them roughly how long it will be. If you transfer a call, tell the caller who they are being passed to and why — and tell your colleague what the call is about so the caller does not have to repeat everything.</p>',
						'script'  => 'Ask before you put someone on hold, and tell them roughly how long it will be. If you transfer a call, tell the caller who they are being passed to and why, and tell your colleague what the call is about so the caller does not have to repeat everything.',
						'seconds' => 45,
					),
				),
			),
			array(
				'title'  => 'Writing emails',
				'slides' => array(
					array(
						'key'     => 'ph_email',
						'image'   => 'laptop-course.svg',
						'alt'     => 'Illustration of a laptop showing a course outline, a video panel and a progress bar.',
						'title'   => 'Emails people can act on',
						'layout'  => 'callout',
						'html'    => '<ul><li>A subject line that says what the email is about.</li><li>The most important point in the first sentence.</li><li>Short paragraphs and plain words.</li><li>A clear next step: what you need, and by when.</li></ul>',
						'script'  => 'A good email has a subject line that says what it is about, the most important point in the first sentence, short paragraphs and plain words, and a clear next step: what you need, and by when.',
						'seconds' => 45,
					),
					array(
						'key'     => 'ph_private',
						'title'   => 'Keeping email private',
						'layout'  => 'text',
						'html'    => '<p>Check the address before you press send — autocomplete makes it easy to pick the wrong person. Share only what the reader needs, and follow your agency\'s rules on what can and cannot be sent by email. When in doubt, ask your supervisor first.</p>',
						'script'  => 'Check the address before you press send, because autocomplete makes it easy to pick the wrong person. Share only what the reader needs, and follow your agency\'s rules on what can and cannot be sent by email. When in doubt, ask your supervisor first.',
						'seconds' => 40,
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 'ph_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. Select everything a useful phone message includes.',
						'q'      => array(
							'type'    => 'mr',
							'prompt'  => 'Select everything a useful phone message includes.',
							'options' => array(
								array( 'text' => 'The caller\'s name and best number', 'correct' => true ),
								array( 'text' => 'What they need, in their words', 'correct' => true ),
								array( 'text' => 'Your opinion of the caller' ),
								array( 'text' => 'When they are free for a call back', 'correct' => true ),
							),
							'feedback_incorrect' => 'Stick to facts the next person can act on: who, what, when, and who it is for.',
							'explain' => 'ph_message',
						),
					),
					array(
						'key'    => 'ph_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. Put each step of answering a call in the right group.',
						'hard'   => true,
						'q'      => array(
							'type'       => 'sort',
							'prompt'     => 'Sort each step: does it belong at the start or the end of a call?',
							'categories' => array( 'Start of the call', 'End of the call' ),
							'items'      => array(
								array( 'text' => 'Say the clinic name and your first name', 'category' => 0 ),
								array( 'text' => 'Confirm who you are speaking to', 'category' => 0 ),
								array( 'text' => 'Summarise what happens next', 'category' => 1 ),
								array( 'text' => 'Check there is nothing else they need', 'category' => 1 ),
							),
							'explain' => 'ph_answer',
						),
					),
					array(
						'key'    => 'ph_q3',
						'title'  => 'Question 3',
						'layout' => 'question',
						'script' => 'Question three. True or false: it is fine to transfer a caller without telling your colleague what the call is about.',
						'q'      => array(
							'type'               => 'tf',
							'prompt'             => 'True or false: it is fine to transfer a caller without telling your colleague what the call is about.',
							'answer'             => false,
							'feedback_incorrect' => 'Brief your colleague so the caller does not have to repeat everything.',
							'explain'            => 'ph_hold',
						),
					),
				),
			),
		),
	),

	/* ------------------------------------------------------------------ */
	'safety'     => array(
		'title'    => 'Safety and Emergency Basics at the Clinic (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. Knowing your building, reporting hazards, and following your clinic\'s emergency plan. General awareness only — not first aid or medical training.',
		'required' => true,
		'groups'   => array( 'staff', 'intern' ),
		'due_days' => 30,
		'credit'   => array( 'Safety', 0.75 ),
		'validity' => 12,
		'modules'  => array(
			array(
				'title'  => 'Know your building',
				'slides' => array(
					array(
						'key'     => 'sf_intro',
						'title'   => 'Everyone has a part to play',
						'layout'  => 'image',
						'image'   => 'safety.svg',
						'alt'     => 'Illustration of a safety board with an exit arrow, a first-aid cross and a warning triangle.',
						'html'    => $wic_demo_disclaimer . '<p>A safe clinic is everyone\'s job. This sample course covers general awareness only. Your agency and clinic set the actual emergency plan, and first aid is taught separately by qualified trainers.</p>',
						'script'  => 'A safe clinic is everyone\'s job. This sample course covers general awareness only. Your agency and clinic set the actual emergency plan, and first aid is taught separately by qualified trainers.',
						'seconds' => 40,
					),
					array(
						'key'     => 'sf_know',
						'title'   => 'Five things to find in your first week',
						'layout'  => 'text',
						'html'    => '<p>Walk the building with your mentor and find each of these. Select each one.</p>',
						'script'  => 'Walk the building with your mentor and find each of these. Select each one.',
						'seconds' => 60,
						'layers'  => array(
							array( 'label' => 'Exits and meeting point', 'content' => 'Every way out of the building, and where everyone gathers outside.' ),
							array( 'label' => 'The emergency plan', 'content' => 'Where the clinic\'s written plan is kept, and who is in charge during an emergency.' ),
							array( 'label' => 'Alarms and phones', 'content' => 'How to raise the alarm, and which phone to use to call for help.' ),
							array( 'label' => 'Safety equipment', 'content' => 'Where the first-aid kit and fire extinguishers are kept — and who is trained to use them.' ),
							array( 'label' => 'Who to tell', 'content' => 'The named person to report a hazard or an incident to.' ),
						),
					),
					array(
						'key'     => 'sf_hazard',
						'title'   => 'Spotting and reporting hazards',
						'layout'  => 'callout',
						'html'    => '<p>Trailing cables, a wet floor, a blocked exit, a door that does not close. If it is safe to fix straight away — pick up the cable, put out the wet-floor sign — do it. If not, keep people away and <strong>report it</strong> using your clinic\'s procedure. Never assume someone else already has.</p>',
						'script'  => 'Trailing cables, a wet floor, a blocked exit, a door that does not close. If it is safe to fix straight away, do it. If not, keep people away and report it using your clinic\'s procedure. Never assume someone else already has.',
						'seconds' => 50,
					),
				),
			),
			array(
				'title'  => 'When something happens',
				'slides' => array(
					array(
						'key'     => 'sf_plan',
						'image'   => 'map-pin.svg',
						'alt'     => 'Illustration of a folded map with a large location pin marking a meeting point.',
						'title'   => 'Follow the plan',
						'layout'  => 'text',
						'html'    => '<p>In an emergency, the clinic\'s plan tells you what to do. In general terms:</p><ol><li>Stay calm and keep yourself safe.</li><li>Raise the alarm and call for help as the plan says.</li><li>Help people leave by the nearest safe exit and go to the meeting point.</li><li>Do not go back inside until you are told it is safe.</li></ol>',
						'script'  => 'In an emergency, the clinic\'s plan tells you what to do. In general terms: stay calm and keep yourself safe, raise the alarm and call for help as the plan says, help people leave by the nearest safe exit and go to the meeting point, and do not go back inside until you are told it is safe.',
						'seconds' => 55,
					),
					array(
						'key'     => 'sf_after',
						'title'   => 'Afterwards',
						'layout'  => 'text',
						'html'    => '<p>Once everyone is safe, write down what happened while it is fresh, report it using your agency\'s incident procedure, and talk to your supervisor. Near misses count too — reporting them is how the next incident is prevented.</p>',
						'script'  => 'Once everyone is safe, write down what happened while it is fresh, report it using your agency\'s incident procedure, and talk to your supervisor. Near misses count too. Reporting them is how the next incident is prevented.',
						'seconds' => 45,
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 'sf_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. You notice an exit is blocked by boxes and you cannot move them safely. What should you do?',
						'q'      => array(
							'type'    => 'mc',
							'prompt'  => 'You notice an exit is blocked by boxes and you cannot move them safely. What should you do?',
							'options' => array(
								array( 'text' => 'Report it straight away using the clinic\'s procedure', 'correct' => true ),
								array( 'text' => 'Leave it — someone has probably reported it', 'feedback' => 'Never assume someone else has reported a hazard.' ),
								array( 'text' => 'Mention it at the next team meeting', 'feedback' => 'A blocked exit needs reporting now, not next week.' ),
							),
							'explain' => 'sf_hazard',
						),
					),
					array(
						'key'    => 'sf_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. Match each item to what you should know about it.',
						'hard'   => true,
						'q'      => array(
							'type'    => 'match',
							'prompt'  => 'Match each item to what you should know about it in your first week.',
							'pairs'   => array(
								array( 'left' => 'Exits', 'right' => 'Every way out, and the meeting point' ),
								array( 'left' => 'The emergency plan', 'right' => 'Where it is kept and who is in charge' ),
								array( 'left' => 'Safety equipment', 'right' => 'Where it is and who is trained to use it' ),
							),
							'explain' => 'sf_know',
						),
					),
					array(
						'key'    => 'sf_q3',
						'title'  => 'Question 3',
						'layout' => 'question',
						'script' => 'Question three. True or false: near misses do not need to be reported if nobody was hurt.',
						'q'      => array(
							'type'               => 'tf',
							'prompt'             => 'True or false: near misses do not need to be reported if nobody was hurt.',
							'answer'             => false,
							'feedback_incorrect' => 'Near misses count — reporting them helps prevent the next incident.',
							'explain'            => 'sf_after',
						),
					),
				),
			),
		),
	),

	/* ------------------------------------------------------------------ */
	'docs'       => array(
		'title'    => 'Documentation Habits That Help Your Team (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. Writing notes that are accurate, timely and useful to the next person who reads them.',
		'required' => false,
		'groups'   => array(),
		'due_days' => 45,
		'credit'   => array( 'Professional development', 0.5 ),
		'validity' => 0,
		'modules'  => array(
			array(
				'title'  => 'Why notes matter',
				'slides' => array(
					array(
						'key'     => 'dc_intro',
						'title'   => 'Written for the next person',
						'layout'  => 'image',
						'image'   => 'documents.svg',
						'alt'     => 'Illustration of a stack of documents with lines of text and a pen resting beside them.',
						'html'    => $wic_demo_disclaimer . '<p>Good notes let a colleague pick up where you left off without having to ask. Your agency\'s policy sets what must be recorded and where — this course is about the habits that make any note more useful.</p>',
						'script'  => 'Good notes let a colleague pick up where you left off without having to ask. Your agency\'s policy sets what must be recorded and where. This course is about the habits that make any note more useful.',
						'seconds' => 35,
					),
					array(
						'key'     => 'dc_good',
						'title'   => 'Four qualities of a good note',
						'layout'  => 'text',
						'html'    => '<p>Select each quality.</p>',
						'script'  => 'Select each quality of a good note.',
						'seconds' => 55,
						'layers'  => array(
							array( 'label' => 'Accurate', 'content' => 'What happened, not what you think happened. Facts first; label anything that is an opinion.' ),
							array( 'label' => 'Timely', 'content' => 'Written as soon as you can — details fade quickly on a busy day.' ),
							array( 'label' => 'Clear', 'content' => 'Plain words, no private shorthand, and readable by someone who was not there.' ),
							array( 'label' => 'Complete enough', 'content' => 'Everything the next person needs to act — and nothing they do not.' ),
						),
					),
					array(
						'key'     => 'dc_fact',
						'title'   => 'Facts, not labels',
						'layout'  => 'callout',
						'html'    => '<p>Compare <em>"Participant was difficult"</em> with <em>"Participant said the appointment time did not work because of their shift, and asked for a later slot."</em> The second tells the next person what actually happened and what to do about it.</p>',
						'script'  => 'Compare, participant was difficult, with, participant said the appointment time did not work because of their shift and asked for a later slot. The second tells the next person what actually happened and what to do about it.',
						'seconds' => 45,
					),
				),
			),
			array(
				'title'  => 'Everyday habits',
				'slides' => array(
					array(
						'key'     => 'dc_when',
						'title'   => 'Write it as you go',
						'layout'  => 'text',
						'html'    => '<p>Set aside a minute after each conversation rather than saving everything for the end of the day. If you remember something later, add it as a dated addition — never change what was written before.</p>',
						'script'  => 'Set aside a minute after each conversation rather than saving everything for the end of the day. If you remember something later, add it as a dated addition. Never change what was written before.',
						'seconds' => 40,
					),
					array(
						'key'     => 'dc_where',
						'image'   => 'checklist.svg',
						'alt'     => 'Illustration of a clipboard checklist with three items ticked and a pen beside it.',
						'title'   => 'The right place',
						'layout'  => 'text',
						'html'    => '<p>Use the system and forms your agency provides. Sticky notes, personal notebooks and private messages get lost — and may not be secure. If you are not sure where something belongs, ask.</p>',
						'script'  => 'Use the system and forms your agency provides. Sticky notes, personal notebooks and private messages get lost, and may not be secure. If you are not sure where something belongs, ask.',
						'seconds' => 35,
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 'dc_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. Which note is more useful to the next person?',
						'hard'   => true,
						'q'      => array(
							'type'    => 'mc',
							'prompt'  => 'Which note is more useful to the next person?',
							'options' => array(
								array( 'text' => '"Asked for a later appointment because of their shift; offered Thursday 4pm."', 'correct' => true ),
								array( 'text' => '"Participant was difficult about times."', 'feedback' => 'A label tells the next person how you felt, not what happened or what to do.' ),
								array( 'text' => '"See me."', 'feedback' => 'The next person may not be able to reach you — write down what they need.' ),
							),
							'explain' => 'dc_fact',
						),
					),
					array(
						'key'    => 'dc_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. Sort each habit into helps or gets in the way.',
						'q'      => array(
							'type'       => 'sort',
							'prompt'     => 'Sort each habit.',
							'categories' => array( 'Helps', 'Gets in the way' ),
							'items'      => array(
								array( 'text' => 'Writing a note straight after the conversation', 'category' => 0 ),
								array( 'text' => 'Keeping notes on sticky notes at your desk', 'category' => 1 ),
								array( 'text' => 'Adding a dated addition when you remember more', 'category' => 0 ),
								array( 'text' => 'Using private shorthand only you understand', 'category' => 1 ),
							),
							'explain' => 'dc_good',
						),
					),
					array(
						'key'    => 'dc_q3',
						'title'  => 'Question 3',
						'layout' => 'question',
						'script' => 'Question three. True or false: if you remember a detail later, you should edit your original note.',
						'q'      => array(
							'type'               => 'tf',
							'prompt'             => 'True or false: if you remember a detail later, you should edit your original note so it reads correctly.',
							'answer'             => false,
							'feedback_incorrect' => 'Add a dated addition instead — never change what was written before.',
							'explain'            => 'dc_when',
						),
					),
				),
			),
		),
	),

	/* ------------------------------------------------------------------ */
	'dashboards' => array(
		'title'    => 'Using Data Dashboards as a Supervisor (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. Reading the team, overdue and compliance views in the portal, and turning them into action.',
		'required' => false,
		'groups'   => array(),
		'due_days' => 30,
		'credit'   => array( 'Leadership', 1 ),
		'validity' => 0,
		'modules'  => array(
			array(
				'title'  => 'Reading the views',
				'slides' => array(
					array(
						'key'     => 'db_intro',
						'title'   => 'From numbers to next steps',
						'layout'  => 'image',
						'image'   => 'dashboard.svg',
						'alt'     => 'Illustration of a dashboard with summary tiles, a bar chart and a donut chart.',
						'html'    => $wic_demo_disclaimer . '<p>The portal gives supervisors a live picture of their team\'s training. This course shows how to read each view and — more importantly — what to do with what you see.</p>',
						'script'  => 'The portal gives supervisors a live picture of their team\'s training. This course shows how to read each view, and more importantly, what to do with what you see.',
						'seconds' => 35,
					),
					array(
						'key'     => 'db_views',
						'title'   => 'The four views you will use most',
						'layout'  => 'text',
						'html'    => '<p>Select each view.</p>',
						'script'  => 'Select each of the four views you will use most.',
						'seconds' => 60,
						'layers'  => array(
							array( 'label' => 'Team progress', 'content' => 'Everyone on your team, worst first: overdue, coming due, then lowest completion.' ),
							array( 'label' => 'Overdue', 'content' => 'Every overdue course, sorted by days late, with one-click reminders and extensions with a reason.' ),
							array( 'label' => 'Compliance report', 'content' => 'Every person against every required course — the view to print for a review.' ),
							array( 'label' => 'Most-missed questions', 'content' => 'Where the whole team gets stuck. Often a sign the content needs work, not the people.' ),
						),
					),
					array(
						'key'     => 'db_status',
						'title'   => 'Five states, in words and colour',
						'layout'  => 'callout',
						'html'    => '<p>Every course is shown as one of: <strong>Complete</strong>, <strong>In progress</strong>, <strong>Coming due</strong>, <strong>Overdue</strong> or <strong>Never assigned</strong>. Status is always written out as well as coloured, so it reads the same on any screen and in print.</p>',
						'script'  => 'Every course is shown as one of five states: complete, in progress, coming due, overdue or never assigned. Status is always written out as well as coloured, so it reads the same on any screen and in print.',
						'seconds' => 45,
					),
				),
			),
			array(
				'title'  => 'Acting on what you see',
				'slides' => array(
					array(
						'key'     => 'db_act',
						'image'   => 'calendar.svg',
						'alt'     => 'Illustration of a wall calendar with one day highlighted and ticked.',
						'title'   => 'A weekly ten-minute routine',
						'layout'  => 'text',
						'html'    => '<ol><li>Open <strong>Overdue</strong> and send reminders — or talk to the person if something is getting in the way.</li><li>Check <strong>Approvals</strong> so no new starter is left waiting.</li><li>Glance at <strong>Coming due</strong> and mention it in your next team check-in.</li><li>Once a month, look at <strong>most-missed questions</strong> and share what you find with the content team.</li></ol>',
						'script'  => 'A weekly ten-minute routine. Open Overdue and send reminders, or talk to the person if something is getting in the way. Check Approvals so no new starter is left waiting. Glance at coming due and mention it in your next team check-in. And once a month, look at the most-missed questions and share what you find with the content team.',
						'seconds' => 60,
					),
					array(
						'key'     => 'db_fair',
						'title'   => 'Using data fairly',
						'layout'  => 'text',
						'html'    => '<p>A number is a conversation starter, not a verdict. Someone may be overdue because of leave, a shift pattern or a course that does not apply to them. Ask first — then extend with a reason, or mark the course not applicable, so the record stays honest.</p>',
						'script'  => 'A number is a conversation starter, not a verdict. Someone may be overdue because of leave, a shift pattern or a course that does not apply to them. Ask first, then extend with a reason, or mark the course not applicable, so the record stays honest.',
						'seconds' => 45,
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 'db_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. Match each question to the view that answers it.',
						'q'      => array(
							'type'    => 'match',
							'prompt'  => 'Match each question to the view that answers it.',
							'pairs'   => array(
								array( 'left' => 'Who is furthest behind?', 'right' => 'Overdue' ),
								array( 'left' => 'Is everyone up to date for a review?', 'right' => 'Compliance report' ),
								array( 'left' => 'Where does the whole team get stuck?', 'right' => 'Most-missed questions' ),
							),
							'explain' => 'db_views',
						),
					),
					array(
						'key'    => 'db_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. A team member is overdue because they were on leave. What is the best response?',
						'q'      => array(
							'type'    => 'mc',
							'prompt'  => 'A team member is overdue because they were on leave. What is the best response?',
							'options' => array(
								array( 'text' => 'Extend the due date and record the reason', 'correct' => true ),
								array( 'text' => 'Send daily reminders until it is done', 'feedback' => 'Ask first — then extend with a reason so the record stays honest.' ),
								array( 'text' => 'Leave it overdue; the report is the report', 'feedback' => 'An honest record explains why. Extend with a reason.' ),
							),
							'explain' => 'db_fair',
						),
					),
					array(
						'key'    => 'db_q3',
						'title'  => 'Question 3',
						'layout' => 'question',
						'script' => 'Question three. Select each state a course can be shown in.',
						'q'      => array(
							'type'    => 'mr',
							'prompt'  => 'Select each state a course can be shown in.',
							'options' => array(
								array( 'text' => 'Coming due', 'correct' => true ),
								array( 'text' => 'Overdue', 'correct' => true ),
								array( 'text' => 'Failed forever' ),
								array( 'text' => 'Never assigned', 'correct' => true ),
							),
							'explain' => 'db_status',
						),
					),
				),
			),
		),
	),

	/* ------------------------------------------------------------------ */
	'humility'   => array(
		'title'    => 'Cultural Humility in Everyday Service (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. Curiosity, respect and asking rather than assuming, in everyday conversations.',
		'required' => false,
		'groups'   => array(),
		'due_days' => 60,
		'credit'   => array( 'Continuing education', 0.75 ),
		'validity' => 0,
		'modules'  => array(
			array(
				'title'  => 'An open approach',
				'slides' => array(
					array(
						'key'     => 'ch_intro',
						'title'   => 'Curious, not certain',
						'layout'  => 'image',
						'image'   => 'culture.svg',
						'alt'     => 'Illustration of three people of different backgrounds seated together at a long table.',
						'html'    => $wic_demo_disclaimer . '<p>Cultural humility means staying curious about each person\'s experience rather than assuming you already know it. It is a habit, not a checklist — and it makes every conversation better.</p>',
						'script'  => 'Cultural humility means staying curious about each person\'s experience rather than assuming you already know it. It is a habit, not a checklist, and it makes every conversation better.',
						'seconds' => 40,
					),
					array(
						'key'     => 'ch_ask',
						'title'   => 'Ask, do not assume',
						'layout'  => 'callout',
						'html'    => '<p>Instead of guessing what someone prefers — how they would like to be addressed, whether they would like an interpreter, which appointment times work — <strong>ask</strong>. "What works best for you?" is almost always the right question.</p>',
						'script'  => 'Instead of guessing what someone prefers, such as how they would like to be addressed, whether they would like an interpreter, or which appointment times work, ask. What works best for you, is almost always the right question.',
						'seconds' => 45,
					),
					array(
						'key'     => 'ch_self',
						'title'   => 'Starting with yourself',
						'layout'  => 'text',
						'html'    => '<p>Select each habit to see how it helps.</p>',
						'script'  => 'Select each habit to see how it helps.',
						'seconds' => 55,
						'layers'  => array(
							array( 'label' => 'Notice your assumptions', 'content' => 'Everyone has them. Noticing them is what stops them shaping the conversation.' ),
							array( 'label' => 'Listen for the person\'s own words', 'content' => 'Use the words people use about themselves, their family and their needs.' ),
							array( 'label' => 'Own mistakes simply', 'content' => 'If you get something wrong, apologise briefly, correct it, and carry on.' ),
						),
					),
				),
			),
			array(
				'title'  => 'In practice',
				'slides' => array(
					array(
						'key'     => 'ch_names',
						'title'   => 'Names and words matter',
						'layout'  => 'callout',
						'html'    => '<p>Learn to say people\'s names the way they say them — and if you are not sure, ask. Use the words people use about themselves and their families, and avoid labels that put people into boxes.</p>',
						'script'  => 'Learn to say people\'s names the way they say them, and if you are not sure, ask. Use the words people use about themselves and their families, and avoid labels that put people into boxes.',
						'seconds' => 40,
					),
					array(
						'key'     => 'ch_feedback',
						'title'   => 'Inviting feedback',
						'layout'  => 'text',
						'html'    => '<p>A simple "Was there anything we could have done better today?" invites the feedback that helps a clinic improve. Listen without defending, thank the person, and share what you heard with your team.</p>',
						'script'  => 'A simple question, was there anything we could have done better today, invites the feedback that helps a clinic improve. Listen without defending, thank the person, and share what you heard with your team.',
						'seconds' => 40,
					),
					array(
						'key'     => 'ch_team',
						'title'   => 'Learning as a team',
						'layout'  => 'image',
						'image'   => 'team.svg',
						'alt'     => 'Illustration of four colleagues standing together behind a table with a check mark above them.',
						'html'    => '<p>Share what you learn with colleagues — a better way to explain something, a resource that helped, feedback a participant gave. Humility grows faster in a team that talks about it.</p>',
						'script'  => 'Share what you learn with colleagues: a better way to explain something, a resource that helped, feedback a participant gave. Humility grows faster in a team that talks about it.',
						'seconds' => 40,
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 'ch_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. You are not sure how someone would like to be addressed. What should you do?',
						'q'      => array(
							'type'    => 'mc',
							'prompt'  => 'You are not sure how someone would like to be addressed. What should you do?',
							'options' => array(
								array( 'text' => 'Ask them what they prefer', 'correct' => true ),
								array( 'text' => 'Guess based on how they look', 'feedback' => 'Asking is almost always better than assuming.' ),
								array( 'text' => 'Avoid using any name', 'feedback' => 'A simple question solves this — ask what they prefer.' ),
							),
							'explain' => 'ch_ask',
						),
					),
					array(
						'key'    => 'ch_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. True or false: if you make a mistake, a brief apology and correction is the right response.',
						'q'      => array(
							'type'             => 'tf',
							'prompt'           => 'True or false: if you get something wrong, a brief apology and correction is the right response.',
							'answer'           => true,
							'feedback_correct' => 'Yes — own it simply, correct it, and carry on.',
							'explain'          => 'ch_self',
						),
					),
				),
			),
		),
	),
);
