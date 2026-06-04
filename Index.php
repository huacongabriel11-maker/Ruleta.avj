<?php
// =====================================================================
// 1. CONFIGURACIÓN DEL SERVIDOR Y BASE DE DATOS
// =====================================================================
$host = 'localhost';
$dbname = 'avj_community';
$username = 'tu_usuario';       // CAMBIA ESTO por tu usuario de base de datos
$password = 'tu_contraseña';    // CAMBIA ESTO por tu contraseña de base de datos

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    if(isset($_POST['action'])) {
        die(json_encode(['status' => 'error', 'msg' => 'Error de conexión a la base de datos.']));
    } else {
        die("Error: No se pudo conectar a la base de datos. Verifica tus credenciales.");
    }
}

// =====================================================================
// 2. LÓGICA DE API (RECIBIR Y RESPONDER A LA RULETA)
// =====================================================================
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // --- VERIFICAR FACTURA ---
    if ($action === 'verificar_factura') {
        $factura = strtoupper(trim($_POST['factura'] ?? ''));

        if ($factura === 'TEST01') {
            echo json_encode(['status' => 'success']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, estado FROM facturas WHERE numero_factura = ?");
        $stmt->execute([$factura]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['status' => 'error', 'msg' => 'Factura inválida o no registrada.']);
        } else if ($row['estado'] === 'usada') {
            echo json_encode(['status' => 'error', 'msg' => 'Esta factura ya fue utilizada.']);
        } else {
            echo json_encode(['status' => 'success']);
        }
        exit;
    }

    // --- GIRAR RULETA ---
    if ($action === 'girar_ruleta') {
        $factura = strtoupper(trim($_POST['factura'] ?? ''));
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));

        // 1. Validar factura nuevamente
        $factura_id = null;
        if ($factura !== 'TEST01') {
            $stmtF = $pdo->prepare("SELECT id, estado FROM facturas WHERE numero_factura = ?");
            $stmtF->execute([$factura]);
            $rowF = $stmtF->fetch(PDO::FETCH_ASSOC);
            if (!$rowF || $rowF['estado'] === 'usada') {
                echo json_encode(['status' => 'error', 'msg' => 'La factura es inválida o ya fue usada.']);
                exit;
            }
            $factura_id = $rowF['id'];
        }

        // 2. Validar código
        $codigo_id = null;
        $tipo_codigo = 'prueba';

        if ($codigo !== 'TESTAVJ') {
            $stmtC = $pdo->prepare("SELECT id, tipo, estado FROM codigos WHERE codigo = ?");
            $stmtC->execute([$codigo]);
            $rowC = $stmtC->fetch(PDO::FETCH_ASSOC);

            if (!$rowC) {
                echo json_encode(['status' => 'error', 'msg' => 'Código inválido.']);
                exit;
            }
            if ($rowC['estado'] === 'usado') {
                echo json_encode(['status' => 'error', 'msg' => 'Este código ya fue utilizado.']);
                exit;
            }
            $codigo_id = $rowC['id'];
            $tipo_codigo = $rowC['tipo'];
        }

        // 3. Determinar el premio
        $premioIndex = 0;
        if ($tipo_codigo === 'siempre_1') {
            $premioIndex = 0; // Cae en $1
        } else if ($tipo_codigo === 'max_5') {
            $premioIndex = rand(0, 4); // Cae entre $1 y $5
        } else if ($tipo_codigo === 'prueba') {
            $premioIndex = rand(0, 19); // Cae en cualquiera
        }

        $premios = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20];
        $premioGanado = $premios[$premioIndex];

        // 4. Quemar los datos en la base de datos
        if ($factura !== 'TEST01' && $codigo !== 'TESTAVJ') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE facturas SET estado = 'usada' WHERE id = ?")->execute([$factura_id]);
                $pdo->prepare("UPDATE codigos SET estado = 'usado' WHERE id = ?")->execute([$codigo_id]);
                $pdo->prepare("INSERT INTO historial_premios (factura_id, codigo_id, premio_ganado) VALUES (?, ?, ?)")
                    ->execute([$factura_id, $codigo_id, $premioGanado]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'msg' => 'Error al registrar el premio.']);
                exit;
            }
        }

        echo json_encode([
            'status' => 'success',
            'premioIndex' => $premioIndex,
            'premioGanado' => $premioGanado
        ]);
        exit;
    }
}
// =====================================================================
// 3. INTERFAZ GRÁFICA (HTML, CSS y JS)
// =====================================================================
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruleta AVJ COMMUNITY</title>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #1e1e2f;
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            overflow: hidden;
        }

        h1 { color: #fca311; margin-bottom: 5px; text-align: center; }

        .pantalla {
            display: flex; flex-direction: column; align-items: center;
            background-color: #14213d; padding: 30px; border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.5); text-align: center;
            width: 90%; max-width: 400px; box-sizing: border-box;
        }

        #pantalla-ruleta, #pantalla-final { display: none; }

        p { color: #a8dadc; margin-bottom: 20px; font-size: 15px; }

        input[type="text"] {
            padding: 12px; font-size: 16px; border-radius: 5px; border: none;
            outline: none; margin-bottom: 15px; text-align: center; width: 100%;
            box-sizing: border-box; text-transform: uppercase;
        }

        button {
            padding: 12px 20px; font-size: 16px; background-color: #fca311;
            color: #14213d; border: none; border-radius: 5px; cursor: pointer;
            font-weight: bold; transition: background 0.3s, transform 0.1s;
            width: 100%; margin-bottom: 10px;
        }

        button:hover { background-color: #e5980b; }
        button:active { transform: scale(0.98); }
        .btn-whatsapp { background-color: #25D366; color: white; }
        .btn-whatsapp:hover { background-color: #1ebe57; }

        .checkbox-container {
            display: flex; align-items: center; justify-content: center;
            gap: 10px; margin-bottom: 20px; font-size: 13px; color: #a8dadc;
            text-align: left;
        }
        .checkbox-container input { cursor: pointer; width: 18px; height: 18px; }

        .wheel-container {
            position: relative; width: 100%; max-width: 300px;
            aspect-ratio: 1 / 1; margin-top: 10px;
        }

        .pointer {
            position: absolute; top: -15px; left: 50%; transform: translateX(-50%);
            width: 0; height: 0; border-left: 15px solid transparent;
            border-right: 15px solid transparent; border-top: 30px solid #e63946;
            z-index: 10; transition: transform 0.1s;
        }

        .pointer.ticking { animation: tick 0.15s infinite alternate; }
        @keyframes tick {
            0% { transform: translateX(-50%) rotate(0deg); }
            100% { transform: translateX(-50%) rotate(-15deg); }
        }

        canvas {
            width: 100%; height: 100%; border-radius: 50%;
            box-shadow: 0 0 15px rgba(0,0,0,0.5);
            transition: transform 4s cubic-bezier(0.25, 0.1, 0.25, 1);
        }

        #result {
            margin-top: 20px; font-size: 22px; font-weight: bold;
            color: #a8dadc; text-align: center; min-height: 30px;
        }

        #premio-texto {
            font-size: 24px; font-weight: bold; color: #a8dadc;
            margin-top: 10px; margin-bottom: 25px; line-height: 1.5;
        }
    </style>
</head>
<body>

    <div id="pantalla-factura" class="pantalla">
        <h1>AVJ COMMUNITY</h1>
        <p>Introduce tu número de factura para acceder a la ruleta</p>
        <input type="text" id="input-factura" placeholder="Ej: 00001704H-Ñ0270" autocomplete="off">
        
        <div class="checkbox-container">
            <input type="checkbox" id="terminos">
            <label for="terminos">Acepto los términos y reglas del sorteo.</label>
        </div>

        <button onclick="verificarFactura()">VERIFICAR FACTURA</button>
    </div>

    <div id="pantalla-ruleta" class="pantalla">
        <h1>AVJ COMMUNITY</h1>
        <p>Introduce tu código para girar</p>

        <div id="caja-controles" style="width: 100%;">
            <input type="text" id="codigo" placeholder="INGRESA TU CÓDIGO..." autocomplete="off">
            <button onclick="girarRuleta()">GIRAR RULETA</button>
        </div>

        <div class="wheel-container">
            <div class="pointer" id="puntero"></div>
            <canvas id="wheel" width="300" height="300"></canvas>
        </div>

        <div id="result"></div>
    </div>

    <div id="pantalla-final" class="pantalla">
        <h1>AVJ COMMUNITY</h1>
        <div id="premio-texto"></div>
        <button class="btn-whatsapp" onclick="compartirWhatsApp()">COMPARTIR EN WHATSAPP</button>
        <button onclick="resetearParaNuevaFactura()">INGRESAR NUEVA FACTURA</button>
    </div>

    <script>
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        
        function playTick() {
            if(audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            osc.connect(gainNode); gainNode.connect(audioCtx.destination);
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(800, audioCtx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(10, audioCtx.currentTime + 0.05);
            gainNode.gain.setValueAtTime(0.1, audioCtx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.05);
            osc.start(); osc.stop(audioCtx.currentTime + 0.05);
        }

        function playWin() {
            if(audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            osc.connect(gainNode); gainNode.connect(audioCtx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(400, audioCtx.currentTime);
            osc.frequency.setValueAtTime(600, audioCtx.currentTime + 0.1);
            osc.frequency.setValueAtTime(1000, audioCtx.currentTime + 0.2);
            gainNode.gain.setValueAtTime(0.3, audioCtx.currentTime); 
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 1);
            osc.start(); osc.stop(audioCtx.currentTime + 1);
        }

        const canvas = document.getElementById("wheel");
        const ctx = canvas.getContext("2d");
        const resultText = document.getElementById("result");
        const puntero = document.getElementById("puntero");
        
        const numSecciones = 20;
        const premios = Array.from({length: numSecciones}, (_, i) => i + 1);
        const colores = ["#e63946", "#f1faee", "#a8dadc", "#457b9d", "#1d3557"];
        
        let rotacionActual = 0;
        let girando = false;
        let facturaActual = ""; 

        window.onload = function() {
            const yaGiro = localStorage.getItem("yaGiroAVJ");
            const premioGuardado = localStorage.getItem("premioAVJ");

            if (yaGiro === "true") {
                document.getElementById("pantalla-factura").style.display = "none";
                document.getElementById("pantalla-final").style.display = "flex";
                document.getElementById("premio-texto").innerHTML = `Ya utilizaste tu giro.<br><br>Tu premio fue:<br>🎉 $${premioGuardado} 🎉`;
            }
        };

        function dibujarRuleta() {
            const arco = (2 * Math.PI) / numSecciones;
            for (let i = 0; i < numSecciones; i++) {
                const angulo = i * arco;
                ctx.beginPath();
                ctx.fillStyle = colores[i % colores.length];
                ctx.moveTo(150, 150);
                ctx.arc(150, 150, 150, angulo, angulo + arco);
                ctx.lineTo(150, 150);
                ctx.fill();
                
                ctx.save();
                ctx.translate(150, 150);
                ctx.rotate(angulo + arco / 2);
                ctx.textAlign = "right";
                ctx.fillStyle = (ctx.fillStyle === "#f1faee") ? "#1d3557" : "#fff";
                ctx.font = "bold 14px Arial";
                ctx.fillText("$" + premios[i], 135, 5);
                ctx.restore();
            }
        }

        async function verificarFactura() {
            const inputFac = document.getElementById("input-factura").value.trim().toUpperCase(); 
            const aceptoTerminos = document.getElementById("terminos").checked;

            if (!aceptoTerminos) {
                alert("Debes aceptar los términos y reglas del sorteo.");
                return;
            }
            if (inputFac === "") {
                alert("Por favor, ingresa una factura.");
                return;
            }

            const formData = new FormData();
            formData.append("action", "verificar_factura");
            formData.append("factura", inputFac);

            try {
                // Al enviar a "", el código PHP superior procesa la petición
                const response = await fetch("", { method: "POST", body: formData });
                const data = await response.json();

                if (data.status === "success") {
                    facturaActual = inputFac;
                    document.getElementById("pantalla-factura").style.display = "none";
                    document.getElementById("pantalla-ruleta").style.display = "flex";
                    if(audioCtx.state === 'suspended') audioCtx.resume();
                    dibujarRuleta();
                } else {
                    alert(data.msg); 
                }
            } catch (error) {
                alert("Error al conectar con el servidor.");
            }
        }

        async function girarRuleta() {
            if (girando) return;
            const inputCodigo = document.getElementById("codigo").value.trim().toUpperCase();
            if (inputCodigo === "") {
                alert("Ingresa un código para girar.");
                return;
            }

            const formData = new FormData();
            formData.append("action", "girar_ruleta");
            formData.append("factura", facturaActual);
            formData.append("codigo", inputCodigo);

            try {
                const response = await fetch("", { method: "POST", body: formData });
                const data = await response.json();

                if (data.status === "error") {
                    alert(data.msg); 
                    return;
                }
                ejecutarAnimacionRuleta(data.premioIndex, data.premioGanado);
            } catch (error) {
                alert("Error de conexión al procesar tu premio.");
            }
        }

        function ejecutarAnimacionRuleta(premioIndex, premioGanado) {
            girando = true;
            document.getElementById("caja-controles").style.display = "none";
            resultText.innerText = "Girando...";

            localStorage.setItem("yaGiroAVJ", "true");
            localStorage.setItem("premioAVJ", premioGanado);

            puntero.classList.add("ticking");
            let tickInterval = setInterval(playTick, 150);

            const arcoGrados = 360 / numSecciones;
            const premioRotacion = 360 - (premioIndex * arcoGrados) - (arcoGrados / 2) - 90;
            const rotacionFinal = rotacionActual + 1800 + (premioRotacion - (rotacionActual % 360));
            
            canvas.style.transform = `rotate(${rotacionFinal}deg)`;
            rotacionActual = rotacionFinal;

            setTimeout(() => {
                girando = false;
                puntero.classList.remove("ticking");
                clearInterval(tickInterval);
                playWin(); 
                
                confetti({ particleCount: 150, spread: 80, origin: { y: 0.6 } });

                resultText.innerHTML = `¡Felicidades! Ganaste $${premioGanado}`;
                
                setTimeout(() => {
                    document.getElementById("pantalla-ruleta").style.display = "none";
                    document.getElementById("pantalla-final").style.display = "flex";
                    document.getElementById("premio-texto").innerHTML = `Ya utilizaste tu giro.<br><br>Tu premio fue:<br>🎉 $${premioGanado} 🎉`;
                }, 3500); 
                
            }, 4000); 
        }

        function resetearParaNuevaFactura() {
            localStorage.removeItem("yaGiroAVJ");
            localStorage.removeItem("premioAVJ");
            facturaActual = "";
            document.getElementById("pantalla-final").style.display = "none";
            document.getElementById("pantalla-factura").style.display = "flex";
            document.getElementById("input-factura").value = ""; 
            document.getElementById("terminos").checked = false;
            document.getElementById("codigo").value = "";
            document.getElementById("caja-controles").style.display = "block";
            document.getElementById("result").innerText = "";
        }

        function compartirWhatsApp() {
            const premioGanado = localStorage.getItem("premioAVJ");
            const mensaje = encodeURIComponent(`¡Acabo de ganar $${premioGanado} en la ruleta de AVJ COMMUNITY! 🚀`);
            window.open(`https://wa.me/?text=${mensaje}`, "_blank");
        }
    </script>
</body>
</html>


