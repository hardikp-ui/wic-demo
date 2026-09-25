<?php
/**
 * The six sample courses. General, non-clinical topics only, so nothing here can be
 * mistaken for approved WIC policy: no statistics, citations, regulations or nutrition advice.
 *
 * Each slide: key, title, layout (text|image|callout|question), html, script, seconds,
 * optional layers (label/content) and q (question array in the platform's _wic_question format;
 * 'explain' names the key of the slide that explains the answer).
 */

defined( 'ABSPATH' ) || exit;

$wic_demo_disclaimer = '<p class="wic-demo-note"><strong>Sample course for demonstration. Replace with agency-approved content.</strong></p>';

return array(

	/* ------------------------------------------------------------------ */
	'welcome'     => array(
		'title'    => 'Welcome to the Clinic Team (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. A first-week orientation: who is who, how a clinic day runs, and where to get help.',
		'required' => true,
		'groups'   => array( 'staff', 'intern' ),
		'due_days' => 14,
		'credit'   => array( 'Orientation', 0.75 ),
		'validity' => 0,
		'modules'  => array(
			array(
				'title'  => 'Your first week',
				'slides' => array(
					array(
						'key'     => 'w_hello',
						'image'   => 'team.svg',
						'alt'     => 'Illustration of four colleagues standing together behind a table with a check mark above them.',
						'title'   => 'Welcome aboard',
						'layout'  => 'text',
						'html'    => $wic_demo_disclaimer . '<p>Welcome to the team. This short course walks you through your first week: the people you will work with, how a clinic day is organised, and where to turn when you are not sure.</p><p>It takes about fifteen minutes. You can stop at any point — your place is saved.</p>',
						'script'  => 'Welcome to the team. This short course walks you through your first week: the people you will work with, how a clinic day is organised, and where to turn when you are not sure. It takes about fifteen minutes, and you can stop at any point. Your place is saved.',
						'seconds' => 40,
						'es'      => array(
							'title'  => 'Bienvenida al equipo (Muestra)',
							'html'   => '<p class="wic-demo-note"><strong>Curso de muestra para demostración. Reemplácelo con contenido aprobado por la agencia.</strong></p><p>Le damos la bienvenida al equipo. Este curso breve le acompaña durante su primera semana: las personas con quienes trabajará, cómo se organiza un día en la clínica y a quién acudir cuando tenga dudas.</p><p>Dura unos quince minutos. Puede detenerse en cualquier momento; su progreso se guarda.</p>',
							'script' => 'Le damos la bienvenida al equipo. Este curso breve le acompaña durante su primera semana: las personas con quienes trabajará, cómo se organiza un día en la clínica y a quién acudir cuando tenga dudas. Dura unos quince minutos, y puede detenerse en cualquier momento. Su progreso se guarda.',
						),
					),
					array(
						'key'     => 'w_people',
						'title'   => 'The people around you',
						'layout'  => 'text',
						'html'    => '<p>Every clinic is organised a little differently, but most teams share the same shape. Select each role to see what it usually covers.</p>',
						'script'  => 'Every clinic is organised a little differently, but most teams share the same shape. Select each role to see what it usually covers.',
						'seconds' => 50,
						'layers'  => array(
							array( 'label' => 'Your supervisor', 'content' => 'Approves your account, assigns your training, and is your first stop for questions about your role, your schedule and your progress.' ),
							array( 'label' => 'Your mentor', 'content' => 'A named colleague who shows you how things are really done, answers the small questions, and checks in with you through your first weeks.' ),
							array( 'label' => 'Front desk and clinic staff', 'content' => 'The people who welcome participants, keep appointments running on time and make sure every visit starts well.' ),
							array( 'label' => 'Agency office', 'content' => 'Sets the policies, forms and training your clinic follows, and supports clinics when something needs a decision above clinic level.' ),
						),
						'es'      => array(
							'title'  => 'Las personas a su alrededor',
							'html'   => '<p>Cada clínica se organiza de manera un poco distinta, pero la mayoría de los equipos tienen una estructura parecida. Seleccione cada función para ver lo que suele incluir.</p>',
							'script' => 'Cada clínica se organiza de manera un poco distinta, pero la mayoría de los equipos tienen una estructura parecida. Seleccione cada función para ver lo que suele incluir.',
							'review' => true,
						),
					),
					array(
						'key'     => 'w_day',
						'image'   => 'calendar.svg',
						'alt'     => 'Illustration of a wall calendar with one day highlighted and ticked.',
						'title'   => 'How a clinic day runs',
						'layout'  => 'callout',
						'html'    => '<p><strong>Before opening:</strong> check the day\'s schedule, the equipment you need, and any messages left overnight.</p><p><strong>During the day:</strong> greet every person, keep the waiting area informed if things run late, and write things down as you go rather than at the end.</p><p><strong>At close:</strong> secure paper records and screens, note anything the next shift needs to know, and tell your supervisor about anything unusual.</p>',
						'script'  => 'Before opening, check the day\'s schedule, the equipment you need, and any messages left overnight. During the day, greet every person, keep the waiting area informed if things run late, and write things down as you go. At close, secure paper records and screens, note anything the next shift needs to know, and tell your supervisor about anything unusual.',
						'seconds' => 60,
					),
					array(
						'key'     => 'w_plan',
						'title'   => 'Your training plan',
						'layout'  => 'image',
						'image'   => 'laptop-course.svg',
						'alt'     => 'Illustration of a laptop showing a course outline, a video panel and a progress bar.',
						'html'    => '<p>Your first month follows a learning path: a few short courses in week one, practice and a workshop in weeks two and three, and the rest in week four. You will find it under <strong>My path</strong>, with each step ticked off as you finish it.</p>',
						'script'  => 'Your first month follows a learning path: a few short courses in week one, practice and a workshop in weeks two and three, and the rest in week four. You will find it under My path, with each step ticked off as you finish it.',
						'seconds' => 40,
					),
					array(
						'key'     => 'w_help',
						'title'   => 'Where to get help',
						'layout'  => 'text',
						'html'    => '<p>Nobody expects you to know everything in your first week. When you are unsure:</p><ol><li>Check the <strong>Library</strong> in the training portal for your agency\'s procedures and quick references.</li><li>Ask your <strong>mentor</strong> — small questions are exactly what they are there for.</li><li>Ask your <strong>supervisor</strong> for anything about policy, safety or a participant\'s situation.</li></ol><p>It is always better to ask than to guess.</p>',
						'script'  => 'Nobody expects you to know everything in your first week. When you are unsure, check the Library in the training portal, ask your mentor, and ask your supervisor for anything about policy, safety or a participant\'s situation. It is always better to ask than to guess.',
						'seconds' => 45,
						'es'      => array(
							'title'  => 'Dónde pedir ayuda',
							'html'   => '<p>Nadie espera que lo sepa todo en su primera semana. Cuando tenga dudas:</p><ol><li>Consulte la <strong>Biblioteca</strong> del portal de capacitación para ver los procedimientos y guías rápidas de su agencia.</li><li>Pregunte a su <strong>mentor</strong>: para eso está, incluso para las preguntas pequeñas.</li><li>Pregunte a su <strong>supervisor</strong> sobre cualquier tema de políticas, seguridad o la situación de un participante.</li></ol><p>Siempre es mejor preguntar que adivinar.</p>',
							'script' => 'Nadie espera que lo sepa todo en su primera semana. Cuando tenga dudas, consulte la Biblioteca del portal de capacitación, pregunte a su mentor, y pregunte a su supervisor sobre cualquier tema de políticas, seguridad o la situación de un participante. Siempre es mejor preguntar que adivinar.',
						),
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 'w_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. Who is usually your first stop for questions about your role and your training?',
						'q'      => array(
							'type'               => 'mc',
							'prompt'             => 'Who is usually your first stop for questions about your role and your training?',
							'options'            => array(
								array( 'text' => 'Your supervisor', 'correct' => true ),
								array( 'text' => 'The agency office', 'feedback' => 'The agency office sets policy, but day-to-day questions about your role go to your supervisor first.' ),
								array( 'text' => 'Whoever is at the front desk', 'feedback' => 'Colleagues can help, but your supervisor is the person responsible for your role and training.' ),
							),
							'feedback_correct'   => 'Right — your supervisor approves your account and assigns your training.',
							'explain'            => 'w_people',
						),
					),
					array(
						'key'    => 'w_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. True or false: it is better to finish your notes at the end of the day than as you go.',
						'q'      => array(
							'type'               => 'tf',
							'prompt'             => 'True or false: it is better to write your notes at the end of the day than as you go.',
							'answer'             => false,
							'feedback_correct'   => 'Correct. Notes written as you go are more accurate and nothing is forgotten.',
							'feedback_incorrect' => 'Notes written as you go are more accurate — details fade by the end of a busy day.',
							'explain'            => 'w_day',
						),
					),
					array(
						'key'    => 'w_q3',
						'title'  => 'Question 3',
						'layout' => 'question',
						'script' => 'Question three. Match each situation to the best person to ask.',
						'hard'   => true,
						'q'      => array(
							'type'    => 'match',
							'prompt'  => 'Match each situation to the best person to ask.',
							'pairs'   => array(
								array( 'left' => 'Where the spare printer paper is kept', 'right' => 'Your mentor' ),
								array( 'left' => 'Whether you can change your working hours', 'right' => 'Your supervisor' ),
								array( 'left' => 'Where to find the clinic\'s procedures', 'right' => 'The portal Library' ),
							),
							'hint'    => 'Think about who is responsible for what, and what the portal holds.',
							'explain' => 'w_help',
						),
					),
				),
			),
		),
	),

	/* ------------------------------------------------------------------ */
	'service'     => array(
		'title'    => 'Customer Service at the Front Desk (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. Greeting people well, listening, and handling a difficult moment calmly.',
		'required' => true,
		'groups'   => array( 'staff' ),
		'due_days' => 30,
		'credit'   => array( 'Continuing education', 1 ),
		'credits'  => array( array( 'type' => 'Continuing education', 'hours' => 1 ), array( 'type' => 'Customer service', 'hours' => 1 ) ),
		'validity' => 24,
		'modules'  => array(
			array(
				'title'  => 'First impressions',
				'slides' => array(
					array(
						'key'     => 's_intro',
						'image'   => 'front-desk.svg',
						'alt'     => 'Illustration of a staff member at a clinic front desk with a computer screen beside them.',
						'title'   => 'Why the front desk matters',
						'layout'  => 'text',
						'html'    => $wic_demo_disclaimer . '<p>The front desk is often the first and last conversation someone has with the clinic. A calm, friendly welcome makes everything that follows easier — for the participant and for the team.</p>',
						'script'  => 'The front desk is often the first and last conversation someone has with the clinic. A calm, friendly welcome makes everything that follows easier, for the participant and for the team.',
						'seconds' => 35,
					),
					array(
						'key'     => 's_greet',
						'title'   => 'A good greeting',
						'layout'  => 'callout',
						'html'    => '<ul><li><strong>Look up and acknowledge</strong> the person as soon as they arrive, even if you are on the phone.</li><li><strong>Introduce yourself</strong> by name.</li><li><strong>Ask how you can help</strong>, rather than assuming why they are here.</li><li><strong>Explain what happens next</strong> and roughly how long it will take.</li></ul>',
						'script'  => 'A good greeting has four parts. Look up and acknowledge the person as soon as they arrive. Introduce yourself by name. Ask how you can help, rather than assuming. And explain what happens next and roughly how long it will take.',
						'seconds' => 55,
					),
					array(
						'key'     => 's_listen',
						'title'   => 'Listening well',
						'layout'  => 'text',
						'html'    => '<p>Listening is more than waiting for your turn to speak. Select each technique to see how it helps.</p>',
						'script'  => 'Listening is more than waiting for your turn to speak. Select each technique to see how it helps.',
						'seconds' => 50,
						'layers'  => array(
							array( 'label' => 'Give your full attention', 'content' => 'Turn towards the person, pause what you are doing, and keep the screen from coming between you.' ),
							array( 'label' => 'Reflect back', 'content' => 'Say back what you heard in your own words — "So the appointment time doesn\'t work because of your shift?" — so the person knows they were understood.' ),
							array( 'label' => 'Ask open questions', 'content' => 'Questions that start with "what" or "how" invite more than a yes or no, and often surface the real need.' ),
						),
					),
				),
			),
			array(
				'title'  => 'When things get difficult',
				'slides' => array(
					array(
						'key'     => 's_calm',
						'image'   => 'conversation.svg',
						'alt'     => 'Illustration of two people talking, each with a speech bubble.',
						'title'   => 'Staying calm in a difficult moment',
						'layout'  => 'text',
						'html'    => '<p>People are sometimes frustrated before they reach the desk — a long wait, a bus that did not come, a form they did not understand. It is rarely about you.</p><p>Keep your voice low and steady, acknowledge the frustration ("I can see this has been a long morning"), and focus on what you <em>can</em> do next. If you feel unsafe, or the situation is escalating, step back and get your supervisor straight away.</p>',
						'script'  => 'People are sometimes frustrated before they reach the desk. It is rarely about you. Keep your voice low and steady, acknowledge the frustration, and focus on what you can do next. If you feel unsafe, or the situation is escalating, step back and get your supervisor straight away.',
						'seconds' => 60,
					),
					array(
						'key'     => 's_close',
						'title'   => 'Closing the conversation',
						'layout'  => 'callout',
						'html'    => '<p>Before someone leaves, check they know what happens next: the date of their next visit, anything they need to bring, and how to reach the clinic. A simple "Is there anything else I can help with today?" catches the question they were too shy to ask.</p>',
						'script'  => 'Before someone leaves, check they know what happens next: the date of their next visit, anything they need to bring, and how to reach the clinic. A simple question like, is there anything else I can help with today, catches the question they were too shy to ask.',
						'seconds' => 45,
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 's_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. Select every part of a good greeting.',
						'q'      => array(
							'type'    => 'mr',
							'prompt'  => 'Select every part of a good greeting.',
							'options' => array(
								array( 'text' => 'Acknowledge the person as soon as they arrive', 'correct' => true ),
								array( 'text' => 'Introduce yourself by name', 'correct' => true ),
								array( 'text' => 'Assume why they have come in, to save time' ),
								array( 'text' => 'Explain what happens next', 'correct' => true ),
							),
							'feedback_incorrect' => 'A good greeting asks how you can help rather than assuming.',
							'explain' => 's_greet',
						),
					),
					array(
						'key'    => 's_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. Sort each phrase into helpful or unhelpful.',
						'hard'   => true,
						'q'      => array(
							'type'       => 'sort',
							'prompt'     => 'Sort each phrase: does it help or get in the way?',
							'categories' => array( 'Helps', 'Gets in the way' ),
							'items'      => array(
								array( 'text' => '"I can see this has been a long morning."', 'category' => 0 ),
								array( 'text' => '"That\'s not my job."', 'category' => 1 ),
								array( 'text' => '"So the time doesn\'t work because of your shift?"', 'category' => 0 ),
								array( 'text' => '"Calm down."', 'category' => 1 ),
							),
							'hint'    => 'Which phrases show the person they were heard?',
							'explain' => 's_calm',
						),
					),
					array(
						'key'    => 's_q3',
						'title'  => 'Question 3',
						'layout' => 'question',
						'script' => 'Question three. A conversation at the desk is escalating and you feel unsafe. What should you do?',
						'q'      => array(
							'type'    => 'mc',
							'prompt'  => 'A conversation at the desk is escalating and you feel unsafe. What should you do?',
							'options' => array(
								array( 'text' => 'Step back and get your supervisor straight away', 'correct' => true ),
								array( 'text' => 'Raise your voice so you are heard', 'feedback' => 'Raising your voice usually escalates things further.' ),
								array( 'text' => 'Carry on and hope it settles', 'feedback' => 'Your safety comes first — get help early.' ),
							),
							'explain' => 's_calm',
						),
					),
				),
			),
		),
	),

	/* ------------------------------------------------------------------ */
	'privacy'     => array(
		'title'    => 'Protecting Participant Privacy — Principles (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. General principles for handling personal information carefully: only what is needed, only with those who need it.',
		'required' => true,
		'groups'   => array( 'staff', 'intern' ),
		'due_days' => 30,
		'credit'   => array( 'Compliance', 0.5 ),
		'validity' => 12,
		'modules'  => array(
			array(
				'title'  => 'Everyday privacy',
				'slides' => array(
					array(
						'key'     => 'p_intro',
						'image'   => 'privacy-lock.svg',
						'alt'     => 'Illustration of a large padlock between two documents.',
						'title'   => 'Why privacy matters',
						'layout'  => 'text',
						'html'    => $wic_demo_disclaimer . '<p>People share personal details with the clinic because they trust it. Protecting that information is part of everyone\'s job, every day. Your agency\'s own policy sets the exact rules — this sample course covers general principles only.</p>',
						'script'  => 'People share personal details with the clinic because they trust it. Protecting that information is part of everyone\'s job, every day. Your agency\'s own policy sets the exact rules. This sample course covers general principles only.',
						'seconds' => 40,
					),
					array(
						'key'     => 'p_need',
						'title'   => 'Only what is needed',
						'layout'  => 'callout',
						'html'    => '<p>Look at, ask for, and share only the information you need for the task in front of you — and only with people who need it to do their job.</p><p>Curiosity is not a reason to open a record.</p>',
						'script'  => 'Look at, ask for, and share only the information you need for the task in front of you, and only with people who need it to do their job. Curiosity is not a reason to open a record.',
						'seconds' => 40,
					),
					array(
						'key'     => 'p_spaces',
						'title'   => 'Screens, paper and conversations',
						'layout'  => 'text',
						'html'    => '<p>Privacy slips most often in ordinary moments. Select each one.</p>',
						'script'  => 'Privacy slips most often in ordinary moments. Select each one.',
						'seconds' => 60,
						'layers'  => array(
							array( 'label' => 'Screens', 'content' => 'Lock your screen whenever you step away, and angle it so people at the desk cannot read it.' ),
							array( 'label' => 'Paper', 'content' => 'Keep paper records out of public view, collect printouts straight away, and use the secure bin for disposal.' ),
							array( 'label' => 'Conversations', 'content' => 'Keep your voice down when discussing someone\'s details, and move to a private space for anything sensitive.' ),
							array( 'label' => 'Phone calls', 'content' => 'Confirm who you are speaking to, using your agency\'s procedure, before discussing any personal details.' ),
						),
					),
					array(
						'key'     => 'p_report',
						'title'   => 'If something goes wrong',
						'layout'  => 'text',
						'html'    => '<p>Mistakes happen — a letter sent to the wrong address, a screen left open. What matters is reporting it quickly so it can be put right.</p><p>Tell your supervisor as soon as you notice, and follow your agency\'s reporting procedure. Reporting a mistake is always the right thing to do.</p>',
						'script'  => 'Mistakes happen. What matters is reporting it quickly so it can be put right. Tell your supervisor as soon as you notice, and follow your agency\'s reporting procedure. Reporting a mistake is always the right thing to do.',
						'seconds' => 45,
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 'p_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. A friend asks whether someone they know came into the clinic today. What should you do?',
						'hard'   => true,
						'q'      => array(
							'type'    => 'mc',
							'prompt'  => 'A friend asks whether someone they know came into the clinic today. What should you do?',
							'options' => array(
								array( 'text' => 'Politely say you cannot share that', 'correct' => true ),
								array( 'text' => 'Tell them, since they already know the person', 'feedback' => 'Even confirming a visit shares personal information. Only people who need it for their job should know.' ),
								array( 'text' => 'Check the record, then decide', 'feedback' => 'Opening a record without a work reason is itself a privacy problem.' ),
							),
							'explain' => 'p_need',
						),
					),
					array(
						'key'    => 'p_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. Sort each action into protects privacy or puts it at risk.',
						'q'      => array(
							'type'       => 'sort',
							'prompt'     => 'Sort each action.',
							'categories' => array( 'Protects privacy', 'Puts it at risk' ),
							'items'      => array(
								array( 'text' => 'Locking your screen when you step away', 'category' => 0 ),
								array( 'text' => 'Leaving printouts on the shared printer', 'category' => 1 ),
								array( 'text' => 'Moving to a private room for a sensitive conversation', 'category' => 0 ),
								array( 'text' => 'Reading a neighbour\'s record out of curiosity', 'category' => 1 ),
							),
							'explain' => 'p_spaces',
						),
					),
					array(
						'key'    => 'p_q3',
						'title'  => 'Question 3',
						'layout' => 'question',
						'script' => 'Question three. True or false: if you make a privacy mistake, you should report it straight away.',
						'q'      => array(
							'type'             => 'tf',
							'prompt'           => 'True or false: if you make a privacy mistake, you should report it straight away.',
							'answer'           => true,
							'feedback_correct' => 'Yes. Quick reporting is what lets a mistake be put right.',
							'explain'          => 'p_report',
						),
					),
				),
			),
		),
	),

	/* ------------------------------------------------------------------ */
	'interpreter' => array(
		'title'    => 'Working with Interpreters (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. Practical habits for clear conversations through an interpreter, in person or by phone.',
		'required' => false,
		'groups'   => array(),
		'due_days' => 45,
		'credit'   => array( 'Continuing education', 0.5 ),
		'validity' => 0,
		'modules'  => array(
			array(
				'title'  => 'Clear conversations',
				'slides' => array(
					array(
						'key'     => 'i_intro',
						'image'   => 'interpreter.svg',
						'alt'     => 'Illustration of an interpreter between two people, with speech bubbles and arrows showing the conversation passing through.',
						'title'   => 'Everyone deserves to understand',
						'layout'  => 'text',
						'html'    => $wic_demo_disclaimer . '<p>When someone prefers another language, a trained interpreter makes sure they understand — and are understood. Your agency\'s procedure explains how to request one. This course covers habits that make the conversation work.</p>',
						'script'  => 'When someone prefers another language, a trained interpreter makes sure they understand, and are understood. Your agency\'s procedure explains how to request one. This course covers habits that make the conversation work.',
						'seconds' => 40,
					),
					array(
						'key'     => 'i_habits',
						'title'   => 'Four habits that help',
						'layout'  => 'callout',
						'html'    => '<ol><li><strong>Speak to the person, not the interpreter</strong> — look at them and use "you".</li><li><strong>Use short sentences</strong> and pause often so nothing is lost.</li><li><strong>Avoid jargon and abbreviations</strong>; they rarely translate well.</li><li><strong>Check understanding</strong> by asking the person to explain back in their own words.</li></ol>',
						'script'  => 'Four habits help. Speak to the person, not the interpreter. Use short sentences and pause often. Avoid jargon and abbreviations. And check understanding by asking the person to explain back in their own words.',
						'seconds' => 55,
					),
					array(
						'key'     => 'i_prepare',
						'title'   => 'Before the conversation',
						'layout'  => 'text',
						'html'    => '<p>A minute of preparation makes the conversation smoother. Tell the interpreter what the visit is about, how long it may take, and any words that are likely to come up. Then let the person know an interpreter is joining and that everything said will be interpreted.</p>',
						'script'  => 'A minute of preparation makes the conversation smoother. Tell the interpreter what the visit is about, how long it may take, and any words that are likely to come up. Then let the person know an interpreter is joining and that everything said will be interpreted.',
						'seconds' => 45,
					),
					array(
						'key'     => 'i_remote',
						'title'   => 'Interpreting by phone or video',
						'layout'  => 'text',
						'html'    => '<p>Remote interpreting works well with a few adjustments. Select each one.</p>',
						'script'  => 'Remote interpreting works well with a few adjustments. Select each one.',
						'seconds' => 50,
						'layers'  => array(
							array( 'label' => 'Check the sound first', 'content' => 'Make sure everyone can hear clearly before you begin, and move away from background noise.' ),
							array( 'label' => 'Say who is speaking', 'content' => 'On the phone nobody can see who is talking — introduce everyone in the room.' ),
							array( 'label' => 'Pause for the interpreter', 'content' => 'Leave a little more time than in person; audio can lag.' ),
						),
					),
					array(
						'key'     => 'i_family',
						'title'   => 'Why not ask a family member?',
						'layout'  => 'text',
						'html'    => '<p>It can seem quicker to let a relative or friend interpret, but they may leave things out, add their own views, or be put in an uncomfortable position — especially children. Follow your agency\'s procedure for offering a trained interpreter.</p>',
						'script'  => 'It can seem quicker to let a relative or friend interpret, but they may leave things out, add their own views, or be put in an uncomfortable position, especially children. Follow your agency\'s procedure for offering a trained interpreter.',
						'seconds' => 45,
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 'i_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. When speaking through an interpreter, who should you look at and speak to?',
						'q'      => array(
							'type'    => 'mc',
							'prompt'  => 'When speaking through an interpreter, who should you look at and speak to?',
							'options' => array(
								array( 'text' => 'The person you are helping', 'correct' => true ),
								array( 'text' => 'The interpreter', 'feedback' => 'Speak directly to the person and use "you" — the interpreter relays it.' ),
							),
							'explain' => 'i_habits',
						),
					),
					array(
						'key'    => 'i_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. Match each habit to why it helps.',
						'hard'   => true,
						'q'      => array(
							'type'    => 'match',
							'prompt'  => 'Match each habit to why it helps.',
							'pairs'   => array(
								array( 'left' => 'Short sentences', 'right' => 'Nothing gets lost in a long passage' ),
								array( 'left' => 'Avoiding jargon', 'right' => 'Plain words translate more reliably' ),
								array( 'left' => 'Explain-back', 'right' => 'Confirms the message was understood' ),
							),
							'explain' => 'i_habits',
						),
					),
				),
			),
		),
	),

	/* ------------------------------------------------------------------ */
	'portal'      => array(
		'title'    => 'Using the Training Portal (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. A ten-minute tour of the portal: your training list, the lesson player, certificates and the library.',
		'required' => true,
		'groups'   => array( 'staff', 'intern' ),
		'due_days' => 7,
		'credit'   => array( 'Orientation', 0.25 ),
		'validity' => 0,
		'modules'  => array(
			array(
				'title'  => 'Finding your way',
				'slides' => array(
					array(
						'key'     => 'u_home',
						'image'   => 'laptop-course.svg',
						'alt'     => 'Illustration of a laptop showing a course outline, a video panel and a progress bar.',
						'title'   => 'Your home page',
						'layout'  => 'text',
						'html'    => $wic_demo_disclaimer . '<p>When you sign in, <strong>Home</strong> shows what you owe: anything overdue, what is due soon, and a <strong>Continue</strong> button that takes you straight back to where you left off.</p>',
						'script'  => 'When you sign in, Home shows what you owe: anything overdue, what is due soon, and a Continue button that takes you straight back to where you left off.',
						'seconds' => 35,
					),
					array(
						'key'     => 'u_player',
						'title'   => 'Inside a lesson',
						'layout'  => 'text',
						'html'    => '<p>Every lesson works the same way. Select each part of the player.</p>',
						'script'  => 'Every lesson works the same way. Select each part of the player.',
						'seconds' => 50,
						'layers'  => array(
							array( 'label' => 'Outline and transcript', 'content' => 'The side panel lists every slide and shows the words of the narration. Your progress is ticked off as you go.' ),
							array( 'label' => 'Notes and bookmarks', 'content' => 'Bookmark a slide or write yourself a private note. Find them all later under My notes.' ),
							array( 'label' => 'Text size and language', 'content' => 'Make the text bigger, or switch language where a course offers one.' ),
							array( 'label' => 'Save and exit', 'content' => 'Leave at any time. Your place is saved on the server, so you can carry on from another computer.' ),
						),
					),
					array(
						'key'     => 'u_library',
						'title'   => 'The Library and ready-written messages',
						'layout'  => 'image',
						'image'   => 'documents.svg',
						'alt'     => 'Illustration of a stack of documents with lines of text and a pen resting beside them.',
						'html'    => '<p>The <strong>Library</strong> holds your agency\'s procedures, quick references and forms, with the current version clearly marked. <strong>Ready messages</strong> gives you approved wording for common texts and emails — copy, fill in the brackets, and send.</p>',
						'script'  => 'The Library holds your agency\'s procedures, quick references and forms, with the current version clearly marked. Ready messages gives you approved wording for common texts and emails. Copy, fill in the brackets, and send.',
						'seconds' => 45,
					),
					array(
						'key'     => 'u_notices',
						'title'   => 'Notifications and reminders',
						'layout'  => 'text',
						'html'    => '<p>The bell in the portal shows new assignments, reminders and anything waiting for you. You will also get a reminder shortly before a course is due, and your supervisor can send a nudge if something is overdue.</p>',
						'script'  => 'The notifications view shows new assignments, reminders and anything waiting for you. You will also get a reminder shortly before a course is due, and your supervisor can send a nudge if something is overdue.',
						'seconds' => 35,
					),
					array(
						'key'     => 'u_certs',
						'image'   => 'certificate.svg',
						'alt'     => 'Illustration of a framed certificate with a ribbon seal.',
						'title'   => 'Certificates and your transcript',
						'layout'  => 'callout',
						'html'    => '<p>When you finish a course, a certificate is issued straight away. Find it under <strong>Certificates</strong>, print it whenever you need to, and download your full training history from <strong>My transcript</strong>. Anyone can check a certificate is genuine using the code printed on it.</p>',
						'script'  => 'When you finish a course, a certificate is issued straight away. Find it under Certificates, print it whenever you need, and download your full training history from My transcript. Anyone can check a certificate is genuine using the code printed on it.',
						'seconds' => 45,
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 'u_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. You stopped a lesson halfway through yesterday. Where is the quickest place to carry on?',
						'q'      => array(
							'type'    => 'mc',
							'prompt'  => 'You stopped a lesson halfway through yesterday. What is the quickest way to carry on?',
							'options' => array(
								array( 'text' => 'The Continue button on Home', 'correct' => true ),
								array( 'text' => 'Start the course again from the beginning', 'feedback' => 'No need — your place is saved. Use Continue on Home.' ),
								array( 'text' => 'Ask your supervisor to reset it', 'feedback' => 'Your place is saved automatically; Continue takes you straight there.' ),
							),
							'explain' => 'u_home',
						),
					),
					array(
						'key'    => 'u_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. True or false: you can only print a certificate on the day you complete the course.',
						'q'      => array(
							'type'               => 'tf',
							'prompt'             => 'True or false: you can only print a certificate on the day you complete the course.',
							'answer'             => false,
							'feedback_incorrect' => 'Certificates are kept in your account — print them whenever you need.',
							'explain'            => 'u_certs',
						),
					),
				),
			),
		),
	),

	/* ------------------------------------------------------------------ */
	'vendor'      => array(
		'title'    => 'Vendor Basics for Store Staff (Sample)',
		'excerpt'  => 'Sample course for demonstration. Replace with agency-approved content. How vendor training works on the portal, and good habits at the checkout. Your agency supplies the actual vendor rules.',
		'required' => false,
		'groups'   => array(),
		'due_days' => 0,
		'credit'   => array( 'Vendor training', 0.5 ),
		'validity' => 0,
		'vendor'   => true,
		'modules'  => array(
			array(
				'title'  => 'Welcome, store team',
				'slides' => array(
					array(
						'key'     => 'v_intro',
						'image'   => 'store-counter.svg',
						'alt'     => 'Illustration of a store counter with a striped awning, shelves of goods and a cashier at the register.',
						'title'   => 'About this training',
						'layout'  => 'text',
						'html'    => $wic_demo_disclaimer . '<p>Authorised stores complete vendor training every year. Your agency supplies the actual rules your store must follow; this sample shows how the training, the certificate and the vendor portal fit together.</p>',
						'script'  => 'Authorised stores complete vendor training every year. Your agency supplies the actual rules your store must follow. This sample shows how the training, the certificate and the vendor portal fit together.',
						'seconds' => 40,
					),
					array(
						'key'     => 'v_checkout',
						'title'   => 'Good habits at the checkout',
						'layout'  => 'callout',
						'html'    => '<ul><li>Treat every shopper with the same courtesy and privacy.</li><li>When you are unsure about a rule, <strong>check the vendor resources</strong> or call the vendor helpline — never guess.</li><li>Make sure new cashiers complete their training before they work the register.</li></ul>',
						'script'  => 'Treat every shopper with the same courtesy and privacy. When you are unsure about a rule, check the vendor resources or call the vendor helpline. Never guess. And make sure new cashiers complete their training before they work the register.',
						'seconds' => 45,
					),
					array(
						'key'     => 'v_help',
						'title'   => 'Getting help',
						'layout'  => 'image',
						'image'   => 'handshake.svg',
						'alt'     => 'Illustration of two hands meeting in a handshake below a star.',
						'html'    => '<p>The vendor portal lists the vendor helpline and your agency\'s vendor resources in one place. Use them whenever a question comes up at the register — the helpline is separate from the participant line, so store questions reach the right team.</p>',
						'script'  => 'The vendor portal lists the vendor helpline and your agency\'s vendor resources in one place. Use them whenever a question comes up at the register. The helpline is separate from the participant line, so store questions reach the right team.',
						'seconds' => 40,
					),
					array(
						'key'     => 'v_cert',
						'title'   => 'Your yearly certificate',
						'layout'  => 'text',
						'html'    => '<p>Completing this course issues a certificate valid until 31 December. It carries a code an inspector can check on the public verification page. In January the training resets and is due again for the new year.</p>',
						'script'  => 'Completing this course issues a certificate valid until thirty-first December. It carries a code an inspector can check on the public verification page. In January the training resets and is due again for the new year.',
						'seconds' => 40,
					),
				),
			),
			array(
				'title'      => 'Check your understanding',
				'assessment' => true,
				'slides'     => array(
					array(
						'key'    => 'v_q1',
						'title'  => 'Question 1',
						'layout' => 'question',
						'script' => 'Question one. You are not sure whether a rule applies at the register. What should you do?',
						'q'      => array(
							'type'    => 'mc',
							'prompt'  => 'You are not sure whether a rule applies at the register. What should you do?',
							'options' => array(
								array( 'text' => 'Check the vendor resources or call the vendor helpline', 'correct' => true ),
								array( 'text' => 'Make your best guess to keep the line moving', 'feedback' => 'Never guess — check the resources or call the helpline.' ),
							),
							'explain' => 'v_checkout',
						),
					),
					array(
						'key'    => 'v_q2',
						'title'  => 'Question 2',
						'layout' => 'question',
						'script' => 'Question two. True or false: the vendor certificate stays valid for several years.',
						'q'      => array(
							'type'             => 'tf',
							'prompt'           => 'True or false: the vendor certificate stays valid for several years.',
							'answer'           => false,
							'feedback_incorrect' => 'It is valid until 31 December; the training resets each year.',
							'explain'          => 'v_cert',
						),
					),
				),
			),
		),
	),
);
