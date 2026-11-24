<?php
$qrServerIp = gethostbyname(gethostname());
if (!filter_var($qrServerIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || strpos($qrServerIp, '127.') === 0) {
    $qrServerIp = '';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مسابقة الحروف التفاعلية</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; background: linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%); min-height: 100vh; text-align: center; margin: 0; padding: 20px; position: relative; }
        .admin-btn { position: absolute; top: 20px; left: 20px; background-color: #2c3e50; color: white; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; font-size: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: all 0.3s ease; display: flex; align-items: center; gap: 8px; }
        .admin-btn:hover { background-color: #1a252f; transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
        h1 { font-weight: 900; color: #2c3e50; font-size: 45px; text-shadow: 2px 2px 4px rgba(0,0,0,0.1); margin-top: 30px; margin-bottom: 10px; }
        .status-board { background: white; padding: 10px 30px; border-radius: 50px; display: inline-block; margin-bottom: 30px; font-size: 22px; box-shadow: 0 10px 20px rgba(0,0,0,0.05); border: 2px solid #eee; }
        
        #game-board { display: grid; grid-template-columns: repeat(5, 80px); gap: 12px; justify-content: center; margin: 0 auto 30px auto; }
        .cell { width: 80px; height: 80px; background-color: #ffffff; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: 900; color: #2c3e50; cursor: pointer; user-select: none; transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275); box-shadow: 0 6px 0 #d1d8e0, 0 10px 10px rgba(0,0,0,0.1); }
        .cell:not(.red):not(.blue):hover { background-color: #f1f2f6; transform: translateY(-2px); box-shadow: 0 8px 0 #d1d8e0, 0 12px 15px rgba(0,0,0,0.1); }
        .cell:not(.red):not(.blue):active { transform: translateY(4px); box-shadow: 0 2px 0 #d1d8e0, 0 5px 5px rgba(0,0,0,0.1); }
        
        .red { background: linear-gradient(145deg, #ff6b6b, #ee5253) !important; color: white !important; box-shadow: 0 6px 0 #b33939, 0 0 15px rgba(238, 82, 83, 0.6) !important; text-shadow: 1px 1px 2px rgba(0,0,0,0.2); transform: translateY(2px); }
        .blue { background: linear-gradient(145deg, #48dbfb, #2e86de) !important; color: white !important; box-shadow: 0 6px 0 #227093, 0 0 15px rgba(46, 134, 222, 0.6) !important; text-shadow: 1px 1px 2px rgba(0,0,0,0.2); transform: translateY(2px); }
        
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; padding: 40px; border-radius: 20px; width: 60%; max-width: 600px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); animation: popIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .answer-box { display: none; margin-top: 25px; padding: 20px; background: #f0fdf4; border: 2px dashed #22c55e; border-radius: 12px; font-weight: bold; font-size: 22px; color: #166534; }
        
        .btn { font-family: 'Cairo', sans-serif; font-weight: bold; padding: 12px 25px; margin: 8px; border: none; border-radius: 8px; cursor: pointer; font-size: 18px; color: white; transition: 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
        .btn:active { transform: translateY(1px); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .btn-red { background: linear-gradient(145deg, #ff6b6b, #ee5253); }
        .btn-blue { background: linear-gradient(145deg, #48dbfb, #2e86de); }
        .btn-show { background: linear-gradient(145deg, #feca57, #ff9f43); color: #2c3e50; }
        .btn-close { background: linear-gradient(145deg, #c8d6e5, #8395a7); color: #2c3e50; }
        .btn-action { font-size: 20px; padding: 15px 30px; border-radius: 50px; margin: 10px;}
        .bg-green { background: linear-gradient(145deg, #1dd1a1, #10ac84); }
        .join-panel { max-width: 520px; margin: 90px auto 30px; padding: 28px; background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(0,0,0,.1); }
        .join-panel input, .join-panel select { width: 100%; padding: 12px; margin: 8px 0 16px; border: 1px solid #ccd3da; border-radius: 8px; box-sizing: border-box; font: inherit; }
        .join-panel label { display: block; text-align: right; font-weight: bold; }
        #join-qr-modal .modal-content { width: min(90%, 420px); padding: 28px; }
        #join-qr-image { display: none; width: min(100%, 280px); aspect-ratio: 1; margin: 18px auto; }
        #join-qr-message { min-height: 1.5em; color: #475569; font-weight: bold; overflow-wrap: anywhere; }
        #join-qr-base-url { width: 100%; box-sizing: border-box; padding: 10px; margin: 8px 0; direction: ltr; }
        #join-qr-link { display: none; overflow-wrap: anywhere; }
        #join-error { color: #b42318; font-weight: bold; margin-top: 12px; }
        #room-status { margin: 10px auto 20px; font-size: 18px; color: #34495e; }
        #player-list { margin: 8px auto 18px; }
        #game-timer { display: inline-block; min-width: 150px; padding: 8px 18px; margin-bottom: 16px; border-radius: 12px; background: #fff; color: #2c3e50; font-size: 24px; font-weight: 900; }
        #sound-toggle { position:fixed; right:18px; bottom:18px; z-index:6000; border:0; border-radius:999px; padding:12px 18px; background:#243b55; color:#fff; font:700 16px 'Cairo',sans-serif; cursor:pointer; box-shadow:0 6px 18px #0003; }
        #sound-toggle:hover { background:#16283d; }
        #game-scores { margin: 8px auto 18px; font-size: 20px; font-weight: bold; }
        #answer-form input { width: min(100%, 460px); box-sizing: border-box; padding: 13px; border: 2px solid #ccd3da; border-radius: 8px; font: inherit; font-size: 19px; }
        #answer-feedback { min-height: 1.5em; font-weight: bold; color: #b42318; }
        #answer-feedback.correct { color: #16803c; }
        #game-board { grid-template-columns: repeat(5, minmax(48px, 80px)); }
        @media (max-width: 520px) {
            body { padding: 12px; }
            h1 { font-size: 30px; margin-top: 70px; }
            #game-board { gap: 8px; }
            .cell { width: min(15vw, 64px); height: min(15vw, 64px); font-size: 25px; }
            .modal-content { width: 88%; padding: 20px; }
            #sound-toggle { right: 12px; bottom: calc(12px + env(safe-area-inset-bottom, 0px)); left: 12px; width: calc(100% - 24px); min-height: 48px; padding: 10px 14px; font-size: 15px; }
        }
        
        #presentation-screen { display:none; position:fixed; inset:0; z-index:5000; background:linear-gradient(135deg,#10182b,#243b55); color:white; padding:24px 4vw; overflow:auto; }
        #presentation-screen.active { display:grid; grid-template-columns:minmax(520px,1.1fr) minmax(340px,.9fr); align-items:center; gap:3vw; }
        #presentation-title { font-size:clamp(36px,4vw,72px); margin:0 0 10px; }
        #presentation-timer, #question-timer { display:inline-block; min-width:150px; padding:8px 18px; border-radius:12px; background:#fff; color:#2c3e50; font-size:24px; font-weight:900; }
        #presentation-timer { margin:0 0 18px; }
        #question-timer { margin:0 auto 12px; }
        #presentation-board { display:grid; grid-template-columns:repeat(5,minmax(72px,110px)); gap:12px; justify-content:center; }
        #presentation-board .cell { width:auto; height:auto; aspect-ratio:1; font-size:clamp(28px,3vw,52px); }
        #presentation-results { background:rgba(255,255,255,.96); color:#243b55; border-radius:24px; padding:30px; min-height:360px; box-shadow:0 20px 50px rgba(0,0,0,.28); }
        #presentation-question { font-size:clamp(24px,2.2vw,42px); line-height:1.5; }
        #presentation-answer { margin-top:20px; padding:18px; border-radius:14px; background:#ecfdf5; color:#166534; font-size:clamp(22px,2vw,36px); font-weight:900; }
        #presentation-winner { display:none; margin-top:18px; border-radius:14px; padding:16px; font-size:30px; font-weight:900; }
        .winning-path { animation:winningFlash .75s ease-in-out infinite alternate!important; filter:saturate(1.35); }
        @media (max-width:900px){ #presentation-screen.active{grid-template-columns:1fr;} #presentation-board{grid-template-columns:repeat(5,minmax(52px,80px));} }
        @keyframes winningFlash { from{transform:scale(.94); box-shadow:0 0 8px white;} to{transform:scale(1.08); box-shadow:0 0 28px 12px #ffe066;} }
        @keyframes popIn { 0% { transform: scale(0.7); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
        @keyframes bounce { 0%, 20%, 50%, 80%, 100% {transform: translateY(0);} 40% {transform: translateY(-20px);} 60% {transform: translateY(-10px);} }
    </style>
</head>
<body data-server-ip="<?= htmlspecialchars($qrServerIp, ENT_QUOTES, 'UTF-8') ?>">

    <button id="sound-toggle" type="button" aria-pressed="false">🔇 تشغيل الصوت والموسيقى</button>
    <a href="admin.php" class="admin-btn"><span>🔒</span> دخول المشرف</a>

    <section id="join-panel" class="join-panel">
        <h2 style="color:#2c3e50;">الانضمام إلى لعبة</h2>
        <p>أدخل رمز الغرفة الذي شاركه المشرف، ثم اكتب اسمك واختر فريقك.</p>
        <form id="join-form">
            <label for="room-code-input">رمز الغرفة</label>
            <input id="room-code-input" name="room_code" maxlength="6" required>
            <label for="player-name">اسم اللاعب</label>
            <input id="player-name" name="name" maxlength="40" required>
            <label for="player-team">الفريق</label>
            <select id="player-team" name="team">
                <option value="red">الفريق الأحمر</option>
                <option value="blue">الفريق الأزرق</option>
            </select>
            <button type="submit" class="btn bg-green">انضم إلى اللعبة</button>
        </form>
        <div id="join-error"></div>
    </section>

    <div id="join-qr-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="join-qr-title">
        <div class="modal-content">
            <h2 id="join-qr-title">امسح الرمز للانضمام</h2>
            <p id="join-qr-message" aria-live="polite"></p>
            <label for="join-qr-base-url">رابط صفحة الانضمام المتاح للهاتف:</label>
            <input id="join-qr-base-url" type="url" placeholder="http://192.168.1.20:8080/letters_game/index.php">
            <button type="button" class="btn btn-blue" id="generate-join-qr">إنشاء رمز QR</button>
            <img id="join-qr-image" alt="رمز QR لفتح صفحة الانضمام إلى المسابقة">
            <p><a id="join-qr-link" target="_blank" rel="noopener">فتح رابط الانضمام</a></p>
            <button type="button" class="btn btn-blue" id="copy-join-link" style="display:none;">نسخ رابط الانضمام</button>
            <button type="button" class="btn btn-close" id="close-join-qr">إغلاق</button>
        </div>
    </div>

    <section id="presentation-screen">
        <div>
            <h1 id="presentation-title">مسابقة الحروف التفاعلية</h1>
            <div id="presentation-scores" style="font-size:26px;font-weight:900;margin-bottom:18px;"></div>
            <div id="presentation-timer">الوقت: --:--</div>
            <div id="presentation-board"></div>
        </div>
        <div id="presentation-results">
            <div id="presentation-status" style="font-size:20px;color:#64748b;font-weight:bold;">بانتظار بدء المسابقة</div>
            <h2 id="presentation-question">اختروا المربعات وأجبوا على الأسئلة</h2>
            <div id="presentation-answer"></div>
            <div id="presentation-winner"></div>
            <button type="button" class="btn btn-blue" id="show-join-qr" style="display:none;">عرض QR لانضمام المتسابقين</button>
        </div>
    </section>

    <main id="game-app" style="display:none;">
    <h1 id="competition-title-display">مسابقة الحروف التفاعلية</h1>
    <div id="room-status"></div>
    <div id="player-list"></div>
    <div id="game-scores"></div>
    <div id="game-timer">الوقت: --:--</div>
    <div class="status-board">الدور الحالي: <span id="current-team" style="color:#ee5253; font-weight:900;">الأحمر</span></div>
    <div id="game-action-feedback" role="status" aria-live="polite" style="min-height:1.5em;margin:-18px auto 16px;color:#b42318;font-weight:900;"></div>
    
    <div id="game-board"></div>
    
    <div>
        <button class="btn btn-action bg-green" onclick="leaveRoom()">الخروج للانضمام إلى غرفة أخرى</button>
    </div>

    <!-- نافذة السؤال -->
    <div id="question-modal" class="modal-overlay">
        <div class="modal-content">
            <h2 id="modal-letter" style="color: #ff9f43; font-size: 50px; font-weight: 900; margin: 0 0 15px 0; text-shadow: 2px 2px 0px #ffeaa7;"></h2>
            <div id="question-timer">الوقت: --:--</div>
            <h3 id="modal-question" style="font-size: 26px; color: #2c3e50; line-height: 1.5;">جاري جلب السؤال...</h3>
            <div id="modal-answer" class="answer-box"></div>
            <div style="margin-top: 30px;">
                <form id="answer-form" style="display:none;">
                    <label for="player-answer" style="display:block; font-size:20px; font-weight:900; margin-bottom:10px;">اكتب إجابتك بأسرع ما يمكن</label>
                    <input id="player-answer" name="answer" type="text" maxlength="120" autocomplete="off" required>
                    <button id="submit-answer-btn" type="submit" class="btn bg-green">إرسال الإجابة</button>
                    <div id="answer-feedback" role="status" aria-live="polite"></div>
                </form>
                <p id="judged-wait-message" style="font-size:20px; font-weight:900; color:#2c3e50;">
                    بانتظار قرار المشرف وتسجيل النتيجة.
                </p>
            </div>
        </div>
    </div>

    <!-- نافذة إعلان الفائز -->
    <div id="win-modal" class="modal-overlay" style="z-index: 2000;">
        <div class="modal-content" style="text-align: center;">
            <h1 style="font-size: 90px; margin: 0; animation: bounce 2s infinite;">🏆</h1>
            <h2 id="win-text" style="font-size: 45px; font-weight: 900; margin: 15px 0;">مبروك!</h2>
            <p id="win-subtext" style="font-size: 22px; color: #555; font-weight: bold;"></p>
            
            <div style="margin-top: 30px;">
                <button class="btn btn-action bg-green" onclick="leaveRoom()">العودة إلى صفحة الانضمام</button>
            </div>
        </div>
    </div>
    </main>

    <script>
        let playerToken = localStorage.getItem('letters_game_token') || '';
        let roomCode = localStorage.getItem('letters_game_room') || '';
        let gameState = null;
        let activeCellIndex = null;
        let shownWinner = null;
        let pollingTimer = null;
        let clockTimer = null;
        let syncInProgress = false;
        let answerSubmitting = false;
        let activeQuestionKey = null;
        let presentationTimer = null;
        let presentationClockTimer = null;
        let presentationState = null;
        let audioContext = null;
        let musicSource = null;
        let musicGain = null;
        let soundEnabled = false;
        let soundPreferred = localStorage.getItem('letters_game_sound') === 'on';
        let soundActivationPromise = null;
        let feedbackTimer = null;
        let lastRejectedCellPointer = null;
        let lastPresentationResult = null;
        let lastPresentationWinner = null;

        const soundToggle = document.getElementById('sound-toggle');
        updateSoundToggle();
        soundToggle.addEventListener('click', toggleSound);

        async function toggleSound() {
            if (soundEnabled) {
                soundEnabled = false;
                soundPreferred = false;
                localStorage.setItem('letters_game_sound', 'off');
                stopBackgroundMusic();
                updateSoundToggle();
                return;
            }

            if (!window.AudioContext && !window.webkitAudioContext) {
                soundToggle.textContent = 'الصوت غير مدعوم في هذا المتصفح';
                soundToggle.disabled = true;
                return;
            }

            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            audioContext = audioContext || new AudioContextClass();
            try {
                soundEnabled = true;
                soundPreferred = true;
                updateSoundToggle();
                const resumeRequest = audioContext.resume();
                playTone(880, 0.24, 0.24, 'sine');
                await resumeRequest;
                localStorage.setItem('letters_game_sound', 'on');
                soundToggle.textContent = '🔊 الصوت يعمل — إيقاف الصوت والموسيقى';
                playSound('start');
                startBackgroundMusic();
                setTimeout(updateSoundToggle, 1800);
            } catch (error) {
                soundEnabled = false;
                soundPreferred = false;
                localStorage.setItem('letters_game_sound', 'off');
                updateSoundToggle();
                soundToggle.textContent = 'تعذر تشغيل الصوت — اضغط للسماح';
                console.error('Unable to start game audio:', error);
            }
        }

        function updateSoundToggle() {
            soundToggle.textContent = soundEnabled ? '🔊 إيقاف الصوت والموسيقى' : '🔇 تشغيل الصوت والموسيقى';
            soundToggle.setAttribute('aria-pressed', soundEnabled ? 'true' : 'false');
        }

        async function activateSavedAudioPreference() {
            if (!soundPreferred || !(window.AudioContext || window.webkitAudioContext)) return;
            if (soundActivationPromise) return soundActivationPromise;
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            audioContext = audioContext || new AudioContextClass();
            soundActivationPromise = audioContext.resume().then(() => {
                soundEnabled = true;
                updateSoundToggle();
                startBackgroundMusic();
            }).finally(() => {
                soundActivationPromise = null;
            });
            return soundActivationPromise;
        }

        function resumeAudioForEffect(callback) {
            if (!soundEnabled || !audioContext) return;
            if (audioContext.state === 'running') {
                callback();
                return;
            }
            if (soundPreferred) {
                activateSavedAudioPreference().then(callback).catch(error => {
                    console.error('Unable to resume game audio for an effect:', error);
                });
            }
        }

        function playTone(frequency, duration, volume, waveform = 'sine', delay = 0) {
            if (!soundEnabled || !audioContext) return;
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            const startAt = audioContext.currentTime + delay;
            oscillator.type = waveform;
            oscillator.frequency.setValueAtTime(frequency, startAt);
            gain.gain.setValueAtTime(0.0001, startAt);
            gain.gain.exponentialRampToValueAtTime(volume, startAt + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, startAt + duration);
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start(startAt);
            oscillator.stop(startAt + duration + 0.02);
        }

        function playSound(type) {
            const patterns = {
                click: [[520, 0.1, 0.14]],
                start: [[523, 0.18, 0.18], [659, 0.22, 0.16, 'sine', 0.12]],
                question: [[440, 0.14, 0.16], [587, 0.2, 0.15, 'sine', 0.1]],
                correct: [[659, 0.18, 0.2], [784, 0.2, 0.2, 'sine', 0.12], [988, 0.32, 0.18, 'sine', 0.25]],
                incorrect: [[740, 0.2, 0.3, 'square'], [520, 0.24, 0.28, 'square', 0.18]],
                winner: [[523, 0.24, 0.2], [659, 0.24, 0.2, 'sine', 0.16], [784, 0.24, 0.2, 'sine', 0.32], [1047, 0.58, 0.18, 'sine', 0.48]]
            };
            resumeAudioForEffect(() => {
                (patterns[type] || []).forEach(([frequency, duration, volume, waveform, delay]) =>
                    playTone(frequency, duration, volume, waveform || 'sine', delay || 0)
                );
            });
        }

        function playApplause() {
            if (!soundEnabled || !audioContext) return;
            if (audioContext.state !== 'running') {
                resumeAudioForEffect(playApplause);
                return;
            }

            const duration = 2.4;
            const sampleRate = audioContext.sampleRate;
            const buffer = audioContext.createBuffer(1, Math.ceil(sampleRate * duration), sampleRate);
            const samples = buffer.getChannelData(0);
            const clapTimes = [0.05, 0.19, 0.34, 0.51, 0.7, 0.88, 1.08, 1.29, 1.5, 1.72, 1.94, 2.16];

            clapTimes.forEach((clapTime, clapIndex) => {
                const start = Math.floor(clapTime * sampleRate);
                const clapLength = Math.floor((0.11 + (clapIndex % 3) * 0.025) * sampleRate);
                const strength = 0.55 + Math.random() * 0.45;
                for (let i = 0; i < clapLength && start + i < samples.length; i++) {
                    const time = i / sampleRate;
                    const envelope = Math.exp(-time * 27);
                    const noise = Math.random() * 2 - 1;
                    samples[start + i] += noise * envelope * strength * 0.34;
                }
            });

            const source = audioContext.createBufferSource();
            const gain = audioContext.createGain();
            source.buffer = buffer;
            gain.gain.value = 0.7;
            source.connect(gain);
            gain.connect(audioContext.destination);
            source.start();
        }

        function startBackgroundMusic() {
            if (!soundEnabled || !audioContext || musicSource) return;
            if (audioContext.state !== 'running') return;

            const melody = [261.63, 329.63, 392, 329.63, 293.66, 349.23, 440, 349.23];
            const noteDuration = 0.8;
            const buffer = audioContext.createBuffer(
                1,
                Math.ceil(audioContext.sampleRate * noteDuration * melody.length),
                audioContext.sampleRate
            );
            const samples = buffer.getChannelData(0);

            melody.forEach((frequency, noteIndex) => {
                const noteStart = Math.floor(noteIndex * noteDuration * audioContext.sampleRate);
                const noteLength = Math.floor(noteDuration * audioContext.sampleRate);
                for (let i = 0; i < noteLength; i++) {
                    const time = i / audioContext.sampleRate;
                    const attack = Math.min(1, time / 0.06);
                    const release = Math.min(1, (noteDuration - time) / 0.12);
                    const envelope = Math.max(0, Math.min(attack, release));
                    const fundamental = Math.sin(2 * Math.PI * frequency * time);
                    const harmonic = Math.sin(2 * Math.PI * frequency * 2 * time) * 0.18;
                    samples[noteStart + i] = (fundamental + harmonic) * envelope * 0.48;
                }
            });

            musicGain = audioContext.createGain();
            musicGain.gain.value = 0.32;
            musicSource = audioContext.createBufferSource();
            musicSource.buffer = buffer;
            musicSource.loop = true;
            musicSource.onended = () => {
                musicSource = null;
                musicGain = null;
                if (soundEnabled && audioContext.state === 'running') startBackgroundMusic();
            };
            musicSource.connect(musicGain);
            musicGain.connect(audioContext.destination);
            musicSource.start();
        }

        function stopBackgroundMusic() {
            if (musicSource) {
                musicSource.onended = null;
                musicSource.stop();
                musicSource.disconnect();
                musicSource = null;
            }
            if (musicGain) {
                musicGain.disconnect();
                musicGain = null;
            }
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && soundPreferred) {
                activateSavedAudioPreference().catch(error => {
                    console.error('Unable to resume game audio after returning to the page:', error);
                });
            }
        });

        const displayMode = new URLSearchParams(window.location.search).get('display') === '1';
        const queryRoom = new URLSearchParams(window.location.search).get('room');
        if (queryRoom) document.getElementById('room-code-input').value = queryRoom.toUpperCase();

        const joinQrModal = document.getElementById('join-qr-modal');
        const joinQrImage = document.getElementById('join-qr-image');
        document.getElementById('show-join-qr').addEventListener('click', function () {
            const code = (queryRoom || '').trim().toUpperCase();
            joinQrImage.style.display = 'none';
            joinQrImage.removeAttribute('src');
            document.getElementById('join-qr-link').style.display = 'none';
            document.getElementById('copy-join-link').style.display = 'none';
            const baseUrlInput = document.getElementById('join-qr-base-url');
            const savedBaseUrl = localStorage.getItem('letters_game_join_base_url') || '';
            const isLocalHost = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname);
            if (isLocalHost) {
                const serverIp = document.body.dataset.serverIp;
                const localUrl = new URL(window.location.href);
                if (serverIp) {
                    localUrl.hostname = serverIp;
                    localUrl.pathname = localUrl.pathname.replace(/(?:admin|index)\.php$/i, 'index.php');
                    localUrl.search = '';
                    localUrl.hash = '';
                }
                baseUrlInput.value = serverIp ? localUrl.href : savedBaseUrl;
            } else {
                baseUrlInput.value = savedBaseUrl || new URL('index.php', window.location.href).href;
            }
            document.getElementById('join-qr-message').textContent = !/^[A-Z0-9]{6}$/.test(code)
                ? 'تعذر تحديد رمز الغرفة من رابط شاشة النتائج.'
                : isLocalHost && !baseUrlInput.value
                    ? 'لا يمكن للهاتف فتح localhost. أدخل عنوان IP لهذا الكمبيوتر على الشبكة، مثل http://192.168.1.20:8080/letters_game/index.php، ثم أنشئ الرمز.'
                    : 'اضغط «إنشاء رمز QR» لعرض رابط الانضمام.';
            joinQrModal.style.display = 'flex';
            if (/^[A-Z0-9]{6}$/.test(code) && baseUrlInput.value) {
                    document.getElementById('generate-join-qr').click();
            }
            if (/^[A-Z0-9]{6}$/.test(code) && baseUrlInput.value) {
                document.getElementById('generate-join-qr').click();
            }
        });
        joinQrImage.onload = function () {
            joinQrImage.style.display = 'block';
            const code = (queryRoom || '').trim().toUpperCase();
            document.getElementById('join-qr-message').textContent =
                'امسح الرمز لفتح صفحة الانضمام إلى الغرفة ' + code + '.';
        };
        joinQrImage.onerror = function () {
            document.getElementById('join-qr-message').textContent =
                'تعذر تحميل صورة QR. استخدم رابط الانضمام أدناه أو تحقق من اتصال الإنترنت.';
            joinQrImage.style.display = 'none';
        };
        document.getElementById('generate-join-qr').addEventListener('click', function () {
            const code = (queryRoom || '').trim().toUpperCase();
            const message = document.getElementById('join-qr-message');
            joinQrImage.style.display = 'none';
            joinQrImage.removeAttribute('src');
            try {
                if (!/^[A-Z0-9]{6}$/.test(code)) throw new Error('تعذر تحديد رمز الغرفة من رابط شاشة النتائج.');
                const baseUrl = document.getElementById('join-qr-base-url').value.trim();
                if (!baseUrl) throw new Error('أدخل رابط صفحة الانضمام الذي يمكن للهاتف الوصول إليه.');
                const inviteUrl = new URL(baseUrl);
                if (!['http:', 'https:'].includes(inviteUrl.protocol) ||
                    ['localhost', '127.0.0.1', '::1'].includes(inviteUrl.hostname)) {
                    throw new Error('استخدم رابطاً أو عنوان IP يمكن للهاتف الوصول إليه، وليس localhost.');
                }
                inviteUrl.pathname = inviteUrl.pathname.replace(/(?:admin|index)\.php$/i, 'index.php');
                if (!inviteUrl.pathname.toLowerCase().endsWith('index.php')) {
                    inviteUrl.pathname = inviteUrl.pathname.replace(/\/?$/, '/') + 'index.php';
                }
                inviteUrl.search = '';
                inviteUrl.hash = '';
                inviteUrl.searchParams.set('room', code);
                localStorage.setItem('letters_game_join_base_url', baseUrl);
                const link = document.getElementById('join-qr-link');
                link.href = inviteUrl.href;
                link.textContent = inviteUrl.href;
                link.style.display = 'inline';
                document.getElementById('copy-join-link').style.display = 'inline-block';
                message.textContent = 'جارٍ إنشاء رمز QR...';
                joinQrImage.src = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' +
                    encodeURIComponent(inviteUrl.href);
            } catch (error) {
                message.textContent = error.message;
            }
        });
        document.getElementById('copy-join-link').addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(document.getElementById('join-qr-link').href);
                document.getElementById('join-qr-message').textContent = 'تم نسخ رابط الانضمام.';
            } catch (error) {
                document.getElementById('join-qr-message').textContent =
                    'تعذر النسخ تلقائياً؛ انسخ رابط الانضمام الظاهر أعلاه.';
            }
        });
        document.getElementById('close-join-qr').addEventListener('click', function () {
            joinQrModal.style.display = 'none';
        });
        joinQrModal.addEventListener('click', function (event) {
            if (event.target === joinQrModal) joinQrModal.style.display = 'none';
        });

        document.getElementById('join-form').addEventListener('submit', async function (event) {
            event.preventDefault();
            if (soundPreferred) {
                try {
                    await activateSavedAudioPreference();
                    playSound('click');
                } catch (error) {
                    console.error('Unable to resume game audio:', error);
                }
            }
            const errorBox = document.getElementById('join-error');
            errorBox.textContent = '';
            try {
                const data = await apiRequest('join', {
                    room_code: document.getElementById('room-code-input').value.trim(),
                    name: document.getElementById('player-name').value.trim(),
                    team: document.getElementById('player-team').value
                });
                playerToken = data.token;
                roomCode = data.room_code;
                localStorage.setItem('letters_game_token', playerToken);
                localStorage.setItem('letters_game_room', roomCode);
                await startGameView();
            } catch (error) {
                errorBox.textContent = error.message;
            }
        });

        document.getElementById('answer-form').addEventListener('submit', async function (event) {
            event.preventDefault();
            if (answerSubmitting || !gameState || gameState.answer_mode !== 'fastest_player' || !gameState.question) return;
            answerSubmitting = true;
            const submitButton = document.getElementById('submit-answer-btn');
            const feedback = document.getElementById('answer-feedback');
            const submittedQuestionKey = activeQuestionKey;
            const submittedCell = gameState.question.cell;
            submitButton.disabled = true;
            feedback.className = '';
            feedback.textContent = 'جارٍ التحقق من الإجابة...';
            try {
                const result = await apiRequest('submit_answer', {
                    answer: document.getElementById('player-answer').value,
                    cell: submittedCell
                });
                if (activeQuestionKey !== submittedQuestionKey) return;
                playSound(result.correct ? 'correct' : 'incorrect');
                feedback.className = result.correct ? 'correct' : '';
                feedback.textContent = result.message;
                if (result.correct) {
                    document.getElementById('player-answer').value = '';
                    await syncGameState();
                } else {
                    document.getElementById('player-answer').select();
                }
            } catch (error) {
                if (activeQuestionKey !== submittedQuestionKey) return;
                if (error.message !== 'انتهى وقت الإجابة.' && error.message !== 'تم تسجيل إجابة لاعب آخر بالفعل.') {
                    playSound('incorrect');
                }
                feedback.className = '';
                feedback.textContent = error.message;
                if (error.message === 'انتهى وقت الإجابة.' || error.message === 'تم تسجيل إجابة لاعب آخر بالفعل.') {
                    await syncGameState();
                }
            } finally {
                answerSubmitting = false;
                submitButton.disabled = false;
            }
        });

        async function apiRequest(action, data = {}, method = 'POST') {
            const options = {method, headers: {'Content-Type': 'application/json'}};
            if (method !== 'GET') {
                options.body = JSON.stringify({...data, token: playerToken});
            }
            const response = await fetch('multiplayer_api.php?action=' + encodeURIComponent(action) +
                (method === 'GET' ? '&token=' + encodeURIComponent(playerToken) : ''), options);
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'تعذر تنفيذ الطلب.');
            return result;
        }

        async function startGameView() {
            document.getElementById('join-panel').style.display = 'none';
            document.getElementById('game-app').style.display = 'block';
            await syncGameState();
            if (!pollingTimer) pollingTimer = setInterval(syncGameState, 1800);
            if (!clockTimer) clockTimer = setInterval(renderTimer, 1000);
        }

        async function syncGameState() {
            if (syncInProgress) return;
            syncInProgress = true;
            try {
                gameState = await apiRequest('state', {}, 'GET');
                renderGame();
            } catch (error) {
                if (error.message === 'انضم إلى غرفة أولاً.' || error.message === 'تعذر العثور على اللاعب أو الغرفة.') {
                    leaveRoom(false);
                } else {
                    document.getElementById('room-status').textContent = 'تعذر الاتصال بالخادم. جارٍ إعادة المحاولة...';
                }
            } finally {
                syncInProgress = false;
            }
        }

        function renderGame() {
            document.getElementById('competition-title-display').textContent =
                gameState.title || 'مسابقة الحروف التفاعلية';
            const board = document.getElementById('game-board');
            board.innerHTML = '';
            gameState.board.forEach((square, index) => {
                const cell = document.createElement('div');
                cell.className = 'cell' + (square.team ? ' ' + square.team : '');
                cell.textContent = square.letter;
                cell.dataset.cellIndex = String(index);
                cell.setAttribute('role', 'button');
                cell.setAttribute('tabindex', '0');
                cell.setAttribute('aria-label', `حرف ${square.letter}${square.team ? '، تم اختياره' : ''}`);
                cell.onclick = () => {
                    if (gameState.status !== 'playing') return;
                    const selectedSquare = gameState.board[index];
                    if (selectedSquare.team) {
                        if (!wasRejectedOnPointer(index)) playSound('incorrect');
                        showGameActionFeedback('هذا المربع تم اختياره مسبقًا.');
                        return;
                    }
                    if (gameState.player.team !== gameState.current_team) {
                        if (!wasRejectedOnPointer(index)) playSound('incorrect');
                        showGameActionFeedback('ليس دور فريقك الآن.');
                        return;
                    }
                    playSound('click');
                    showGameActionFeedback('');
                    openQuestion(index);
                };
                board.appendChild(cell);
            });

            const teamName = gameState.current_team === 'red' ? 'الأحمر (المسار الأفقي)' : 'الأزرق (المسار العمودي)';
            const teamColor = gameState.current_team === 'red' ? '#ee5253' : '#2e86de';
            const currentTeam = document.getElementById('current-team');
            currentTeam.textContent = teamName;
            currentTeam.style.color = teamColor;
            const playerTeamName = gameState.player.team === 'red' ? 'الأحمر' : 'الأزرق';
            const count = gameState.players.length;
            let statusText = `الغرفة ${gameState.room_code} — أنت: ${gameState.player.name} (${playerTeamName})`;
            if (gameState.status === 'lobby') {
                statusText += ` — بانتظار اللاعبين: ${count} من ${gameState.max_players}`;
            } else if (gameState.status === 'playing') {
                statusText += ' — اللعبة بدأت';
            } else {
                statusText += ' — انتهت اللعبة';
            }
            document.getElementById('room-status').textContent = statusText;
            document.getElementById('player-list').textContent = 'اللاعبون: ' +
                gameState.players.map(player => `${player.player_name} (${player.team === 'red' ? 'أحمر' : 'أزرق'})`).join('، ');
            document.getElementById('game-scores').innerHTML =
                `<span style="color:#ee5253">الأحمر: ${gameState.scores.red}</span> &nbsp;—&nbsp; ` +
                `<span style="color:#2e86de">الأزرق: ${gameState.scores.blue}</span>`;
            renderTimer();

            if (gameState.question && gameState.status === 'playing') {
                renderQuestion(gameState.question);
            } else {
                closeModal();
            }
            if (gameState.winner && shownWinner !== gameState.winner) {
                shownWinner = gameState.winner;
                playSound('winner');
                playApplause();
                showWinModal(gameState.winner);
            } else if (!gameState.winner) {
                shownWinner = null;
                document.getElementById('win-modal').style.display = 'none';
            }
        }

        function showGameActionFeedback(message) {
            const feedback = document.getElementById('game-action-feedback');
            feedback.textContent = message;
            if (feedbackTimer) clearTimeout(feedbackTimer);
            if (message) {
                feedbackTimer = setTimeout(() => {
                    feedback.textContent = '';
                    feedbackTimer = null;
                }, 2500);
            }
        }

        function wasRejectedOnPointer(index) {
            if (!lastRejectedCellPointer || lastRejectedCellPointer.index !== index ||
                performance.now() - lastRejectedCellPointer.time > 1200) return false;
            lastRejectedCellPointer = null;
            return true;
        }

        async function openQuestion(cellIndex) {
            if (!gameState || gameState.status !== 'playing' ||
                gameState.player.team !== gameState.current_team || gameState.board[cellIndex].team) return;
            try {
                await apiRequest('select', {cell: cellIndex});
                playSound('question');
                await syncGameState();
            } catch (error) {
                document.getElementById('room-status').textContent = error.message;
            }
        }

        function renderQuestion(question) {
            activeCellIndex = question.cell;
            const questionKey = `${question.cell}:${question.question}:${gameState.timer_deadline}`;
            const questionChanged = questionKey !== activeQuestionKey;
            if (questionChanged) {
                activeQuestionKey = questionKey;
                document.getElementById('player-answer').value = '';
                document.getElementById('answer-feedback').textContent = '';
                document.getElementById('answer-feedback').className = '';
            }
            document.getElementById('modal-letter').textContent = 'حرف ' + gameState.board[question.cell].letter;
            document.getElementById('modal-question').textContent = question.question;
            const raceMode = gameState.answer_mode === 'fastest_player';
            document.getElementById('answer-form').style.display = raceMode ? 'block' : 'none';
            document.getElementById('judged-wait-message').style.display = raceMode ? 'none' : 'block';
            if (raceMode && questionChanged) {
                document.getElementById('player-answer').focus();
            }
            document.getElementById('question-modal').style.display = 'flex';
        }

        function renderTimer() {
            const deadline = gameState ? gameState.timer_deadline : null;
            [document.getElementById('game-timer'), document.getElementById('question-timer')]
                .forEach(timer => renderCountdown(timer, deadline));
        }

        function renderCountdown(timer, deadline) {
            if (deadline === null || deadline === undefined) {
                timer.textContent = 'الوقت: --:--';
                timer.style.color = '#2c3e50';
                return;
            }
            const remaining = Math.max(0, deadline - Math.floor(Date.now() / 1000));
            const minutes = Math.floor(remaining / 60).toString().padStart(2, '0');
            const seconds = (remaining % 60).toString().padStart(2, '0');
            timer.textContent = `الوقت: ${minutes}:${seconds}`;
            timer.style.color = remaining <= 10 ? '#b42318' : '#2c3e50';
        }

        function leaveRoom(reload = true) {
            localStorage.removeItem('letters_game_token');
            localStorage.removeItem('letters_game_room');
            if (pollingTimer) clearInterval(pollingTimer);
            if (clockTimer) clearInterval(clockTimer);
            if (reload) window.location.href = 'index.php';
            else {
                document.getElementById('game-app').style.display = 'none';
                document.getElementById('join-panel').style.display = 'block';
            }
        }

        function closeModal() {
            document.getElementById('question-modal').style.display = 'none';
            activeCellIndex = null;
            activeQuestionKey = null;
        }

        function showWinModal(team) {
            const modal = document.getElementById('win-modal');
            const winText = document.getElementById('win-text');
            const winSubtext = document.getElementById('win-subtext');
            
            if (team === 'red') {
                winText.innerText = "فاز الفريق الأحمر! 🔴";
                winText.style.color = "#ee5253";
                winSubtext.innerText = "نجح في بناء مسار متصل من اليمين إلى اليسار.";
            } else {
                winText.innerText = "فاز الفريق الأزرق! 🔵";
                winText.style.color = "#2e86de";
                winSubtext.innerText = "نجح في بناء مسار متصل من الأعلى إلى الأسفل.";
            }
            
            modal.style.display = 'flex';
        }

        function findWinningPath(board, team) {
            const queue = [];
            const previous = {};
            for (let i = 0; i < 25; i++) {
                const row = Math.floor(i / 5), col = i % 5;
                const start = team === 'red' ? col === 0 : row === 0;
                if (start && board[i].team === team) { queue.push(i); previous[i] = null; }
            }
            let end = -1;
            for (let q = 0; q < queue.length; q++) {
                const i = queue[q], row = Math.floor(i / 5), col = i % 5;
                if ((team === 'red' && col === 4) || (team === 'blue' && row === 4)) { end = i; break; }
                [[row-1,col-1],[row-1,col],[row-1,col+1],[row,col-1],[row,col+1],[row+1,col-1],[row+1,col],[row+1,col+1]].forEach(([nr,nc]) => {
                    if (nr < 0 || nr > 4 || nc < 0 || nc > 4) return;
                    const ni = nr * 5 + nc;
                    if (!(ni in previous) && board[ni].team === team) { previous[ni] = i; queue.push(ni); }
                });
            }
            const path = [];
            while (end >= 0) { path.push(end); end = previous[end]; }
            return path;
        }

        async function refreshPresentation() {
            try {
                const params = new URLSearchParams({action:'display_state', room:queryRoom});
                const response = await fetch('multiplayer_api.php?' + params.toString(), {headers:{'Accept':'application/json'}});
                const state = await response.json();
                if (!response.ok) throw new Error(state.error || 'تعذر تحميل شاشة المسابقة');
                presentationState = state;
                const canShowJoinQr = state.status === 'lobby' && state.players_count < state.max_players;
                document.getElementById('show-join-qr').style.display = canShowJoinQr ? 'inline-block' : 'none';
                if (!canShowJoinQr) joinQrModal.style.display = 'none';
                const latest = state.results && state.results.length ? state.results[state.results.length - 1] : null;
                const latestResultKey = latest ? `${latest.resolved_at || ''}:${latest.cell}:${latest.team || 'none'}` : null;
                if (latestResultKey && latestResultKey !== lastPresentationResult) {
                    if (lastPresentationResult !== null) playSound(latest.team ? 'correct' : 'incorrect');
                    lastPresentationResult = latestResultKey;
                }
                if (state.winner && state.winner !== lastPresentationWinner) {
                    lastPresentationWinner = state.winner;
                    playSound('winner');
                    playApplause();
                } else if (!state.winner) {
                    lastPresentationWinner = null;
                }
                renderPresentationTimer();
                document.getElementById('presentation-title').textContent = state.title || 'مسابقة الحروف التفاعلية';
                document.getElementById('presentation-scores').innerHTML =
                    `<span style="color:#ff7b7b">الأحمر: ${state.scores.red}</span> &nbsp;—&nbsp; <span style="color:#70c6ff">الأزرق: ${state.scores.blue}</span>`;
                const boardEl = document.getElementById('presentation-board');
                boardEl.innerHTML = '';
                const winningPath = state.winner ? findWinningPath(state.board, state.winner) : [];
                state.board.forEach((square,index) => {
                    const cell = document.createElement('div');
                    cell.className = 'cell' + (square.team ? ' ' + square.team : '') + (winningPath.includes(index) ? ' winning-path' : '');
                    cell.textContent = square.letter;
                    boardEl.appendChild(cell);
                });
                document.getElementById('presentation-status').textContent = state.status === 'finished' ? 'انتهت المسابقة' : 'نتيجة السؤال الأخير';
                document.getElementById('presentation-question').textContent = latest ? latest.question : (state.question ? state.question.question : 'بانتظار اختيار مربع');
                const answerEl = document.getElementById('presentation-answer');
                answerEl.replaceChildren();
                if (latest) {
                    const correctAnswer = document.createElement('div');
                    correctAnswer.textContent = 'الإجابة الصحيحة: ' + (latest.answer || '—');
                    const answeredBy = document.createElement('div');
                    answeredBy.style.marginTop = '8px';
                    answeredBy.style.color = '#2c3e50';
                    answeredBy.textContent = 'من أجاب: ' +
                        (latest.player_name || (latest.team === 'red' ? 'الفريق الأحمر' : latest.team === 'blue' ? 'الفريق الأزرق' : 'لم يجب أحد'));
                    answerEl.append(correctAnswer, answeredBy);
                } else if (state.question && state.question.answer_visible) {
                    answerEl.textContent = 'الإجابة: ' + state.question.answer;
                }
                const winnerEl = document.getElementById('presentation-winner');
                if (state.winner) {
                    winnerEl.style.display = 'block';
                    winnerEl.style.background = state.winner === 'red' ? '#fee2e2' : '#dbeafe';
                    winnerEl.style.color = state.winner === 'red' ? '#b91c1c' : '#1d4ed8';
                    winnerEl.textContent = state.winner === 'red' ? '🏆 فاز الفريق الأحمر ومساره يومض الآن' : '🏆 فاز الفريق الأزرق ومساره يومض الآن';
                } else winnerEl.style.display = 'none';
            } catch (error) {
                document.getElementById('presentation-status').textContent = error.message;
            }
        }

        function renderPresentationTimer() {
            renderCountdown(
                document.getElementById('presentation-timer'),
                presentationState ? presentationState.timer_deadline : null
            );
        }

        if (displayMode) {
            document.getElementById('join-panel').style.display = 'none';
            document.getElementById('game-app').style.display = 'none';
            document.querySelector('.admin-btn').style.display = 'none';
            document.getElementById('presentation-screen').classList.add('active');
            refreshPresentation();
            presentationTimer = setInterval(refreshPresentation, 1500);
            presentationClockTimer = setInterval(renderPresentationTimer, 1000);
        } else if (playerToken && roomCode) {
            startGameView().catch(() => leaveRoom(false));
        }

        document.addEventListener('pointerdown', function (event) {
            if (!(event.target instanceof Element)) return;
            const cell = event.target.closest('#game-board .cell');
            if (cell && gameState && gameState.status === 'playing') {
                const index = Number(cell.dataset.cellIndex);
                const selectedSquare = gameState.board[index];
                const rejected = selectedSquare.team || gameState.player.team !== gameState.current_team;
                if (rejected) {
                    lastRejectedCellPointer = {index, time: performance.now()};
                    playSound('incorrect');
                    showGameActionFeedback(selectedSquare.team
                        ? 'هذا المربع تم اختياره مسبقًا.'
                        : 'ليس دور فريقك الآن.');
                }
            }

            if (!soundPreferred || event.target.closest('#sound-toggle')) return;
            try {
                const resume = activateSavedAudioPreference();
                if (event.target.closest('.btn, .admin-btn')) {
                    if (audioContext?.state === 'running') playSound('click');
                    else resume.then(() => playSound('click')).catch(error => {
                        console.error('Unable to play the button sound:', error);
                    });
                }
            } catch (error) {
                console.error('Unable to resume game audio:', error);
            }
        });

        document.addEventListener('keydown', async function () {
            if (!soundPreferred) return;
            try {
                await activateSavedAudioPreference();
            } catch (error) {
                console.error('Unable to resume game audio:', error);
            }
        }, {once: true});
    </script>
</body>
</html>