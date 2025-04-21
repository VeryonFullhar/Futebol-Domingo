<?php
session_start();
require 'config.php';
date_default_timezone_set('America/Sao_Paulo');

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Consulta a partida de hoje
$today = date("Y-m-d");
$stmt = $conn->prepare("SELECT id, data, local FROM matches WHERE DATE(data) = ? LIMIT 1");
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();
$match = $result->fetch_assoc();
$stmt->close();

if (!$match) {
    echo "<h2>Não há partida hoje.</h2>";
    echo '<a href="dashboard.php" class="btn btn-primary">Voltar ao Dashboard</a>';
    exit();
}

// Consulta jogadores confirmados (jogadores de linha – não goleiros) usando overall da tabela player_stats
$stmt = $conn->prepare("
    SELECT u.id, u.nome, ps.overall 
    FROM users u 
    INNER JOIN match_presence p ON u.id = p.user_id 
    INNER JOIN player_stats ps ON u.id = ps.jogador_id 
    WHERE p.match_id = ? AND p.status = 'confirmado' AND u.goleiro = 0 
    ORDER BY ps.overall DESC
");
$stmt->bind_param("i", $match['id']);
$stmt->execute();
$result = $stmt->get_result();
$confirmedPlayers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Consulta goleiros confirmados
$stmt = $conn->prepare("
    SELECT u.id, u.nome 
    FROM users u 
    INNER JOIN match_presence p ON u.id = p.user_id 
    WHERE p.match_id = ? AND p.status = 'confirmado' AND u.goleiro = 1
");
$stmt->bind_param("i", $match['id']);
$stmt->execute();
$result = $stmt->get_result();
$goalkeepers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Recuperar o último sorteio e método de sorteio da sessão (se existir)
$ultimoSorteio = isset($_SESSION['ultimo_sorteio']) ? $_SESSION['ultimo_sorteio'] : null;
$metodoSorteioSalvo = isset($_SESSION['metodo_sorteio']) ? $_SESSION['metodo_sorteio'] : 'certeiro';
$atrasadosSalvos = isset($_SESSION['atrasados']) ? $_SESSION['atrasados'] : [];
$atrasadosPresentesSalvos = isset($_SESSION['atrasados_presentes']) ? $_SESSION['atrasados_presentes'] : [];

// Se o formulário for submetido (sortear times)
$teams = array();
$error = "";
$metodoUsado = "";

if (isset($_POST['sortear'])) {
    // Recebe os avulsos enviados via campo oculto (JSON) e decodifica
    $avulsos = array();
    if (isset($_POST['avulsos_json']) && !empty($_POST['avulsos_json'])) {
        $avulsos = json_decode($_POST['avulsos_json'], true);
    }
    
    // Recebe os atrasados enviados via campo oculto (JSON) e decodifica
    $atrasados = array();
    if (isset($_POST['atrasados_json']) && !empty($_POST['atrasados_json'])) {
        $atrasados = json_decode($_POST['atrasados_json'], true);
    }
    
    // Recebe os atrasados presentes via campo oculto (JSON) e decodifica
    $atrasadosPresentes = array();
    if (isset($_POST['atrasados_presentes_json']) && !empty($_POST['atrasados_presentes_json'])) {
        $atrasadosPresentes = json_decode($_POST['atrasados_presentes_json'], true);
    }
    
    // Salva na sessão
    $_SESSION['atrasados'] = $atrasados;
    $_SESSION['atrasados_presentes'] = $atrasadosPresentes;
    
    // Obtém o método de sorteio selecionado
    $metodoSorteio = isset($_POST['metodo_sorteio']) ? $_POST['metodo_sorteio'] : 'certeiro';
    
    // Salva o método de sorteio na sessão
    $_SESSION['metodo_sorteio'] = $metodoSorteio;
    
    // Combina os jogadores confirmados com os avulsos, mas trata os atrasados
    $allPlayers = array_merge($confirmedPlayers, $avulsos);
    
    // Remove os atrasados que ainda não chegaram dos primeiros times
    $playersParaTimeAB = array_filter($allPlayers, function($player) use ($atrasados, $atrasadosPresentes) {
        // Se o jogador está na lista de atrasados e não está na lista de presentes, remove
        foreach ($atrasados as $atrasado) {
            if (isset($player['id']) && isset($atrasado['id']) && $player['id'] == $atrasado['id']) {
                // Verifica se este atrasado já está presente
                $presente = false;
                foreach ($atrasadosPresentes as $atrasadoPresente) {
                    if ($atrasadoPresente['id'] == $atrasado['id']) {
                        $presente = true;
                        break;
                    }
                }
                return $presente; // Se está presente, inclui no sorteio de TimeAB
            }
        }
        return true; // Se não é atrasado, inclui normalmente
    });
    
    // Separa os atrasados que ainda não chegaram
    $playersAtrasados = array_filter($allPlayers, function($player) use ($atrasados, $atrasadosPresentes) {
        foreach ($atrasados as $atrasado) {
            if (isset($player['id']) && isset($atrasado['id']) && $player['id'] == $atrasado['id']) {
                // Verifica se este atrasado já está presente
                foreach ($atrasadosPresentes as $atrasadoPresente) {
                    if ($atrasadoPresente['id'] == $atrasado['id']) {
                        return false; // Está presente, não inclui na lista de atrasados
                    }
                }
                return true; // Está na lista de atrasados e não está presente
            }
        }
        return false; // Não é atrasado
    });
    
    // Transforma os arrays em arrays indexados
    $playersParaTimeAB = array_values($playersParaTimeAB);
    $playersAtrasados = array_values($playersAtrasados);
    
    $totalPlayers = count($playersParaTimeAB);
    
    // Verifica se há pelo menos 2 times completos (10 jogadores de linha)
    if ($totalPlayers < 10) {
        $error = "Número insuficiente de jogadores para formar pelo menos 2 times completos (10 jogadores de linha).";
    } else {
        // Definir a capacidade dos times com base nos jogadores disponíveis (excluindo atrasados)
        $baseCapacity = 5;
        $teamsNeeded = 2; // Pelo menos 2 times
        $teamCapacities = [5, 5, 0, 0];
        
        // Cria um terceiro time se houver jogadores suficientes
        $remaining = $totalPlayers - ($baseCapacity * 2);
        if ($remaining > 0) {
            $teamsNeeded = 3;
            $teamCapacities[2] = min($remaining, 5);
            $remaining -= $teamCapacities[2];
            
            // Cria um quarto time se ainda sobrar jogadores
            if ($remaining > 0) {
                $teamsNeeded = 4;
                $teamCapacities[3] = min($remaining, 5);
            }
        }

        // Aplica o método de sorteio selecionado
        switch ($metodoSorteio) {
            case 'certeiro':
                $teams = sorteioCerteiro($playersParaTimeAB, $teamsNeeded, $teamCapacities);
                $metodoUsado = "Método Certeiro";
                break;
            case 'randomico':
                $teams = sorteioRandomico($playersParaTimeAB, $teamsNeeded, $teamCapacities);
                $metodoUsado = "Método Randômico";
                break;
            case 'margem':
                $teams = sorteioMargem($playersParaTimeAB, $teamsNeeded, $teamCapacities, 3); // Margem de 3 jogadores
                $metodoUsado = "Método de Margem";
                break;
            default:
                $teams = sorteioCerteiro($playersParaTimeAB, $teamsNeeded, $teamCapacities);
                $metodoUsado = "Método Certeiro (padrão)";
        }
        
        // Adiciona os atrasados ao Time C ou cria um Time C se necessário
        if (count($playersAtrasados) > 0) {
            // Se o Time C já existe, adiciona os atrasados a ele
            if (isset($teams[2])) {
                $teams[2]['players'] = array_merge($teams[2]['players'], $playersAtrasados);
            } 
            // Senão, cria um Time C com os atrasados
            else {
                $teamsNeeded = 3;
                $teams[2] = [
                    'players' => $playersAtrasados,
                    'capacity' => count($playersAtrasados)
                ];
            }
        }
        
        // Sorteia os goleiros: embaralha a lista e atribui um para cada time, se disponível
        shuffle($goalkeepers);
        for ($i = 0; $i < $teamsNeeded; $i++) {
            $teams[$i]['goalkeeper'] = isset($goalkeepers[$i]) ? $goalkeepers[$i] : null;
        }
        
        // Salva o resultado do sorteio na sessão
        $_SESSION['ultimo_sorteio'] = $teams;
    }
} else if ($ultimoSorteio) {
    // Recupera o último sorteio da sessão
    $teams = $ultimoSorteio;
    $metodoUsado = "Último sorteio ($metodoSorteioSalvo)";
}

// IMPLEMENTAÇÃO DOS MÉTODOS DE SORTEIO

// Método 1: Certeiro (método original)
function sorteioCerteiro($players, $teamsNeeded, $teamCapacities) {
    // Ordena os jogadores por overall (maior primeiro)
    usort($players, function($a, $b) {
        return $b['overall'] <=> $a['overall'];
    });
    
    // Inicializa os times e soma dos overalls para equilíbrio
    $teams = [];
    $teamSums = [];
    for ($i = 0; $i < $teamsNeeded; $i++) {
        $teams[$i] = ['players' => [], 'capacity' => $teamCapacities[$i]];
        $teamSums[$i] = 0;
    }
    
    // Distribuição dos jogadores usando a lógica greedy:
    // Para cada jogador (em ordem decrescente), atribuir ao time (entre os que ainda não estão completos)
    // que possui a menor soma atual de overalls.
    foreach ($players as $player) {
        $timeMenorSoma = null;
        $menorSoma = null;
        for ($i = 0; $i < $teamsNeeded; $i++) {
            if (count($teams[$i]['players']) < $teams[$i]['capacity']) {
                if ($timeMenorSoma === null || $teamSums[$i] < $menorSoma) {
                    $timeMenorSoma = $i;
                    $menorSoma = $teamSums[$i];
                }
            }
        }
        if ($timeMenorSoma !== null) {
            $teams[$timeMenorSoma]['players'][] = $player;
            $teamSums[$timeMenorSoma] += $player['overall'];
        }
    }
    
    return $teams;
}

// Método 2: Randômico com restrições
function sorteioRandomico($players, $teamsNeeded, $teamCapacities) {
    // Inicializa times e capacidades
    $maxTentativas = 100; // Número máximo de tentativas para encontrar um sorteio equilibrado
    $melhorDiferenca = PHP_FLOAT_MAX;
    $melhorTime = null;
    $maxDiferencaAceitavel = 10; // Diferença máxima aceitável entre times
    
    for ($tentativa = 0; $tentativa < $maxTentativas; $tentativa++) {
        // Embaralha os jogadores para uma distribuição aleatória
        $playersShuffled = $players;
        shuffle($playersShuffled);
        
        // Inicializa os times
        $timesTentativa = [];
        $teamSums = [];
        for ($i = 0; $i < $teamsNeeded; $i++) {
            $timesTentativa[$i] = ['players' => [], 'capacity' => $teamCapacities[$i]];
            $teamSums[$i] = 0;
        }
        
        // Distribui os jogadores aleatórios nos times
        foreach ($playersShuffled as $player) {
            $timeMenorSoma = null;
            $menorSoma = null;
            for ($i = 0; $i < $teamsNeeded; $i++) {
                if (count($timesTentativa[$i]['players']) < $timesTentativa[$i]['capacity']) {
                    if ($timeMenorSoma === null || $teamSums[$i] < $menorSoma) {
                        $timeMenorSoma = $i;
                        $menorSoma = $teamSums[$i];
                    }
                }
            }
            if ($timeMenorSoma !== null) {
                $timesTentativa[$timeMenorSoma]['players'][] = $player;
                $teamSums[$timeMenorSoma] += $player['overall'];
            }
        }
        
        // Calcula a diferença entre o time mais forte e o mais fraco
        $maxSum = max($teamSums);
        $minSum = min($teamSums);
        $diferenca = $maxSum - $minSum;
        
        // Se a diferença for menor que a melhor encontrada, atualiza
        if ($diferenca < $melhorDiferenca) {
            $melhorDiferenca = $diferenca;
            $melhorTime = $timesTentativa;
            
            // Se a diferença for aceitável, encerra as tentativas
            if ($diferenca <= $maxDiferencaAceitavel) {
                break;
            }
        }
    }
    
    return $melhorTime;
}

// Método 3: Margem de aleatoriedade
function sorteioMargem($players, $teamsNeeded, $teamCapacities, $margemAleatoriedade = 2) {
    // Inicializa os times e soma dos overalls para equilíbrio
    $teams = [];
    $teamSums = [];
    for ($i = 0; $i < $teamsNeeded; $i++) {
        $teams[$i] = ['players' => [], 'capacity' => $teamCapacities[$i]];
        $teamSums[$i] = 0;
    }
    
    // Cria uma cópia dos jogadores que será modificada durante o processo
    $availablePlayers = $players;
    
    // Enquanto houver jogadores disponíveis
    while (count($availablePlayers) > 0) {
        // Encontra o time com menor pontuação total que ainda não esteja cheio
        $timeMenorSoma = null;
        $menorSoma = null;
        for ($i = 0; $i < $teamsNeeded; $i++) {
            if (count($teams[$i]['players']) < $teams[$i]['capacity']) {
                if ($timeMenorSoma === null || $teamSums[$i] < $menorSoma) {
                    $timeMenorSoma = $i;
                    $menorSoma = $teamSums[$i];
                }
            }
        }
        
        if ($timeMenorSoma === null) {
            break; // Todos os times estão completos
        }
        
        // Ordena os jogadores por overall (maior primeiro)
        usort($availablePlayers, function($a, $b) {
            return $b['overall'] <=> $a['overall'];
        });
        
        // Determina o número de jogadores a considerar para a seleção aleatória
        $numConsiderar = min($margemAleatoriedade, count($availablePlayers));
        
        // Seleciona aleatoriamente um jogador entre os N melhores disponíveis
        $indiceAleatorio = mt_rand(0, $numConsiderar - 1);
        $jogadorSelecionado = $availablePlayers[$indiceAleatorio];
        
        // Remove o jogador selecionado da lista de disponíveis
        array_splice($availablePlayers, $indiceAleatorio, 1);
        
        // Adiciona o jogador ao time com menor pontuação
        $teams[$timeMenorSoma]['players'][] = $jogadorSelecionado;
        $teamSums[$timeMenorSoma] += $jogadorSelecionado['overall'];
    }
    
    return $teams;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sorteador de Times</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #ADFF2F;
            --primary-dark: #9CE82B;
            --secondary: #8B008B;
            --secondary-hover: #FF00FF;
            --dark: #1C1C1C;
            --dark-panel: #2A2A2A;
            --table-header: #383838;
            --text-light: #E6E6FA;
            --danger: #8B0000;
            --danger-hover: #A52A2A;
        }
        
        body { 
            background: var(--dark); 
            color: #fff; 
            font-family: 'Roboto', Arial, sans-serif; 
            padding: 0; 
            margin: 0;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px 15px;
        }
        
        .panel {
            background: var(--dark-panel);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            margin-bottom: 20px;
        }
        
        .section-title {
            padding-bottom: 10px;
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            font-weight: 600;
            color: white;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 20px; 
            border-radius: 8px;
            overflow: hidden;
            color: white;
        }
        
        thead tr { 
            background: var(--table-header); 
            color: white;
        }
        
        th { 
            background: var(--primary); 
            color: var(--dark); 
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: white;
        }
        
        td { 
            padding: 10px 15px; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            color: white;
        }
        
        tbody tr:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }
        
        .btn-primary { 
            background: var(--primary); 
            color: var(--dark); 
            border: none;
            padding: 10px 20px; 
            border-radius: 5px; 
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-primary:hover { 
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-secondary { 
            background: var(--secondary); 
            color: var(--text-light); 
            border: none;
            padding: 10px 20px; 
            border-radius: 5px; 
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover { 
            background: var(--secondary-hover);
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: var(--danger);
            color: var(--text-light);
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-danger:hover {
            background: var(--danger-hover);
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.9rem;
        }
        
        .form-control {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        
        .form-control:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: var(--primary);
            color: white;
            box-shadow: none;
            outline: none;
        }
        
        label {
            margin-bottom: 8px;
            display: block;
        }
        
        .radio-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .radio-option input {
            margin-right: 8px;
        }
        
        .info-box {
            background: rgba(173, 255, 47, 0.1);
            border-left: 4px solid var(--primary);
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        
        .method-description {
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
            background: rgba(255, 255, 255, 0.05);
            display: none;
        }
        
        .method-description.active {
            display: block;
        }
        
        .badge {
            padding: 5px 10px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: normal;
            margin-left: 8px;
        }
        
        .badge-primary {
            background: var(--primary);
            color: var(--dark);
        }
        
        .badge-secondary {
            background: var(--secondary);
            color: white;
        }
        
        .badge-danger {
            background: var(--danger);
            color: white;
        }
        
        .stats-box {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .stats-box ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .stats-box li {
            margin-bottom: 5px;
        }
        
        .team-card {
            background: var(--dark-panel);
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        
        .team-header {
            background: var(--primary);
            color: var(--dark);
            padding: 12px 15px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .team-header.team-b {
            background: var(--secondary);
            color: white;
        }
        
        .team-header.team-c {
            background: #FF8C00;
            color: var(--dark);
        }
        
        .team-header.team-d {
            background: #20B2AA;
            color: white;
        }
        
        .team-body {
            padding: 15px;
        }
        
        .player-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .player-item {
            padding: 10px 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .player-item:last-child {
            border-bottom: none;
        }
        
        .goalkeeper {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .tab {
            padding: 12px 20px;
            cursor: pointer;
            position: relative;
            color: rgba(255, 255, 255, 0.7);
            transition: all 0.3s;
        }
        
        .tab.active {
            color: var(--primary);
        }
        
        .tab.active:after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary);
        }
        
        .tab:hover {
            color: white;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .flex-between {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .container {
                padding: 15px 10px;
            }
            
            .panel {
                padding: 15px;
            }
            
            .grid-2 {
                grid-template-columns: 1fr;
            }
            
            .radio-group {
                flex-direction: column;
                gap: 10px;
            }
            
            th, td {
                padding: 8px 10px;
            }
            
            .tabs {
                overflow-x: auto;
                white-space: nowrap;
                scrollbar-width: none; /* Firefox */
            }
            
            .tabs::-webkit-scrollbar {
                display: none; /* Chrome and Safari */
            }
            
            .tab {
                padding: 12px 15px;
            }
            
            .btn {
                width: 100%;
                margin-bottom: 10px;
            }
            
            .flex-between {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .flex-between > * {
                margin-bottom: 10px;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade {
            animation: fadeIn 0.3s ease-out forwards;
        }
        
        /* Status indicators */
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-indicator.atrasado {
            background-color: #FFD700;
        }
        
        .status-indicator.presente {
            background-color: #32CD32;
        }
        
        /* Toggle switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(255, 255, 255, 0.2);
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--primary);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .switch-label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .switch-label span {
            margin-left: 10px;
        }
        
        .add-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
            align-items: center;
        }
        
        .add-form input {
            flex: 1;
            min-width: 150px;
        }
        
        .add-form button {
            flex-shrink: 0;
        }
        
        /* Toast notification */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary);
            color: var(--dark);
            padding: 12px 20px;
            border-radius: 5px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            z-index: 1000;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.3s;
        }
        
        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .toast.error {
            background: var(--danger);
            color: white;
        }
        
        /* Atrasados styling */
        .atrasado-tag {
            display: inline-block;
            background: #FFD700;
            color: black;
            padding: 2px 8px;
            border-radius: 15px;
            font-size: 0.8rem;
            margin-left: 5px;
        }
        
        .presente-tag {
            display: inline-block;
            background: #32CD32;
            color: black;
            padding: 2px 8px;
            border-radius: 15px;
            font-size: 0.8rem;
            margin-left: 5px;
        }
        
        /* Loading spinner */
        .spinner {
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top: 3px solid var(--primary);
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            margin-right: 10px;
            display: inline-block;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Arrays para armazenar os avulsos e atrasados (persistidos via localStorage)
    var avulsosPool = [];
    var atrasadosPool = [];
    var atrasadosPresentesPool = [];

    // Função para mostrar notificação
    function showToast(message, error = false) {
        var toast = $('<div class="toast' + (error ? ' error' : '') + '">' + message + '</div>');
        $("body").append(toast);
        
        setTimeout(function() {
            toast.addClass("show");
        }, 100);
        
        setTimeout(function() {
            toast.removeClass("show");
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 3000);
    }
    
    // Dispara evento quando os dados são alterados
    function triggerDataChangedEvent() {
        $(document).trigger("playerDataChanged");
    }

    // Atualiza a lista de avulsos
    function atualizarAvulsosPool() {
        var html = "";
        if (avulsosPool.length === 0) {
            html = "<tr><td colspan='3'>Nenhum avulso adicionado.</td></tr>";
        } else {
            avulsosPool.forEach(function(item, index) {
                html += "<tr>" +
                    "<td>" + item.nome + "</td>" +
                    "<td>" + item.overall + "</td>" +
                    "<td><button type='button' class='btn btn-danger btn-sm removeAvulso' data-index='" + index + "'><i class='fas fa-trash'></i></button></td>" +
                    "</tr>";
            });
        }
        $("#avulsosTableBody").html(html);
        
        // Atualiza o campo oculto com o JSON dos avulsos
        $("#avulsos_json").val(JSON.stringify(avulsosPool));
        
        // Salva no localStorage
        localStorage.setItem("avulsosPool", JSON.stringify(avulsosPool));
        
        // Atualiza o pool completo
        atualizarPoolCompleto();
        
        // Dispara evento de dados alterados
        triggerDataChangedEvent();
    }
    
    // Atualiza a lista de atrasados
    function atualizarAtrasadosPool() {
        var html = "";
        if (atrasadosPool.length === 0) {
            html = "<tr><td colspan='4'>Nenhum jogador marcado como atrasado.</td></tr>";
        } else {
            atrasadosPool.forEach(function(item, index) {
                var presente = isAtrasadoPresente(item.id);
                
                html += "<tr" + (presente ? " class='bg-success bg-opacity-25'" : "") + ">" +
                    "<td>" + item.id + "</td>" +
                    "<td>" + item.nome + "</td>" +
                    "<td>" + item.overall + "</td>" +
                    "<td>" +
                    (presente 
                        ? "<button type='button' class='btn btn-sm btn-danger removerPresente' data-id='" + item.id + "'><i class='fas fa-times'></i> Remover Presença</button>" 
                        : "<button type='button' class='btn btn-sm btn-success marcarPresente' data-id='" + item.id + "'><i class='fas fa-check'></i> Marcar Presente</button>") +
                    " <button type='button' class='btn btn-sm btn-danger removerAtrasado' data-index='" + index + "'><i class='fas fa-trash'></i></button>" +
                    "</td>" +
                    "</tr>";
            });
        }
        $("#atrasadosTableBody").html(html);
        
        // Atualiza os campos ocultos
        $("#atrasados_json").val(JSON.stringify(atrasadosPool));
        $("#atrasados_presentes_json").val(JSON.stringify(atrasadosPresentesPool));
        
        // Salva no localStorage
        localStorage.setItem("atrasadosPool", JSON.stringify(atrasadosPool));
        localStorage.setItem("atrasadosPresentesPool", JSON.stringify(atrasadosPresentesPool));
        
        // Atualiza o pool completo
        atualizarPoolCompleto();
        
        // Dispara evento de dados alterados
        triggerDataChangedEvent();
    }
    
    // Verifica se um atrasado está presente
    function isAtrasadoPresente(id) {
        return atrasadosPresentesPool.some(function(item) {
            return item.id == id;
        });
    }
    
    // Atualiza a tabela de pool completo (confirmados + avulsos + atrasados)
    function atualizarPoolCompleto() {
        var htmlPoolCompleto = "";
        var confirmedPlayers = [];
        
        // Recupera os jogadores confirmados da tabela HTML
        $("#confirmedPlayersTable tr").each(function(index) {
            if (index > 0) { // Pula o cabeçalho
                var id = $(this).find("td:nth-child(1)").text();
                var nome = $(this).find("td:nth-child(2)").text();
                var overall = $(this).find("td:nth-child(3)").text();
                
                // Verifica se é um jogador atrasado
                var isAtrasado = false;
                var isPresente = false;
                
                atrasadosPool.forEach(function(atrasado) {
                    if (atrasado.id == id) {
                        isAtrasado = true;
                        // Verifica se o atrasado já chegou
                        atrasadosPresentesPool.forEach(function(presente) {
                            if (presente.id == id) {
                                isPresente = true;
                            }
                        });
                    }
                });
                
                confirmedPlayers.push({
                    id: id,
                    nome: nome,
                    overall: overall,
                    atrasado: isAtrasado,
                    presente: isPresente
                });
            }
        });
        
        // Combina jogadores confirmados com avulsos
        var allPlayers = [...confirmedPlayers];
        avulsosPool.forEach(function(item) {
            allPlayers.push({
                id: "Avulso",
                nome: item.nome,
                overall: item.overall,
                atrasado: false,
                presente: false
            });
        });
        
        // Ordena por overall (maior primeiro)
        allPlayers.sort(function(a, b) {
            return b.overall - a.overall;
        });
        
        // Gera o HTML para a tabela
        for (var i = 0; i < allPlayers.length; i++) {
            var player = allPlayers[i];
            htmlPoolCompleto += "<tr>" +
                "<td class='numero-ordem'>" + (i + 1) + "</td>" +
                "<td>" + player.nome + 
                    (player.id == "Avulso" ? " <span class='badge badge-secondary'>Avulso</span>" : "");
                    
            if (player.atrasado) {
                htmlPoolCompleto += player.presente 
                    ? " <span class='badge badge-primary'><i class='fas fa-check'></i> Presente</span>" 
                    : " <span class='badge badge-danger'><i class='fas fa-clock'></i> Atrasado</span>";
            }
            
            htmlPoolCompleto += "</td>" +
                "<td>" + player.overall + "</td>";
                
            // Se não for avulso, adiciona botão para marcar como atrasado
            if (player.id != "Avulso" && !player.atrasado) {
                htmlPoolCompleto += "<td><button type='button' class='btn btn-warning btn-sm marcarAtrasado' data-id='" + player.id + "' data-nome='" + player.nome + "' data-overall='" + player.overall + "'><i class='fas fa-clock'></i></button></td>";
            } else {
                htmlPoolCompleto += "<td></td>";
            }
            
            htmlPoolCompleto += "</tr>";
        }
        
        $("#poolCompletoBody").html(htmlPoolCompleto);
    }

    $(document).ready(function(){
        // Inicializa as abas
        $(".tab").click(function() {
            $(".tab").removeClass("active");
            $(this).addClass("active");
            
            $(".tab-content").removeClass("active");
            $("#" + $(this).data("target")).addClass("active");
        });
        
        // Mostrar/esconder descrições dos métodos
        $('input[name="metodo_sorteio"]').change(function() {
            let metodoId = $(this).val();
            $('.method-description').removeClass('active');
            $('#info-' + metodoId).addClass('active');
        });
        
        // Recupera dados do localStorage
        function carregarDadosLocalStorage() {
            // Recupera avulsos
            var storedAvulsos = localStorage.getItem("avulsosPool");
            if (storedAvulsos) {
                try {
                    avulsosPool = JSON.parse(storedAvulsos);
                    atualizarAvulsosPool();
                } catch(e) {
                    console.error("Erro ao ler avulsos do localStorage:", e);
                }
            }
            
            // Recupera atrasados
            var storedAtrasados = localStorage.getItem("atrasadosPool");
            if (storedAtrasados) {
                try {
                    atrasadosPool = JSON.parse(storedAtrasados);
                } catch(e) {
                    console.error("Erro ao ler atrasados do localStorage:", e);
                }
            }
            
            // Recupera atrasados presentes
            var storedAtrasadosPresentes = localStorage.getItem("atrasadosPresentesPool");
            if (storedAtrasadosPresentes) {
                try {
                    atrasadosPresentesPool = JSON.parse(storedAtrasadosPresentes);
                } catch(e) {
                    console.error("Erro ao ler atrasados presentes do localStorage:", e);
                }
            }
            
            // Inicializa método de sorteio
            var storedMetodo = localStorage.getItem("metodoSorteio");
            if (storedMetodo) {
                $('input[name="metodo_sorteio"][value="' + storedMetodo + '"]').prop('checked', true).trigger('change');
            } else {
                // Se não houver método salvo, usa o do PHP ou o padrão
                let phpMetodo = "<?= $metodoSorteioSalvo ?>";
                if (phpMetodo) {
                    $('input[name="metodo_sorteio"][value="' + phpMetodo + '"]').prop('checked', true).trigger('change');
                } else {
                    $('input[name="metodo_sorteio"][value="certeiro"]').prop('checked', true).trigger('change');
                }
            }
            
            // Atualiza tabelas
            atualizarAtrasadosPool();
            atualizarPoolCompleto();
            
            // Dispara evento para inicializar contadores
            triggerDataChangedEvent();
        }
        
        // Carrega dados do localStorage
        carregarDadosLocalStorage();
        
        // Adicionar avulso
        $("#addAvulsoForm").submit(function(e) {
            e.preventDefault();
            var nome = $("#avulsoNome").val().trim();
            var overall = $("#avulsoOverall").val().trim();
            
            if (nome !== "" && overall !== "") {
                avulsosPool.push({
                    nome: nome,
                    overall: parseFloat(overall)
                });
                
                atualizarAvulsosPool();
                $("#avulsoNome").val("");
                $("#avulsoOverall").val("");
                showToast("Avulso adicionado com sucesso!");
            } else {
                showToast("Por favor, preencha nome e nota do avulso.", true);
            }
        });
        
        // Remover avulso
        $(document).on("click", ".removeAvulso", function(){
            var idx = $(this).data("index");
            avulsosPool.splice(idx, 1);
            atualizarAvulsosPool();
            showToast("Avulso removido!");
        });
        
        // Marcar jogador como atrasado
        $(document).on("click", ".marcarAtrasado", function(){
            var id = $(this).data("id");
            var nome = $(this).data("nome");
            var overall = $(this).data("overall");
            
            // Verifica se já está marcado como atrasado
            var jaAtrasado = atrasadosPool.some(function(item) {
                return item.id == id;
            });
            
            if (!jaAtrasado) {
                atrasadosPool.push({
                    id: id,
                    nome: nome,
                    overall: overall
                });
                
                atualizarAtrasadosPool();
                showToast("Jogador marcado como atrasado!");
            }
        });
        
        // Remover jogador dos atrasados
        $(document).on("click", ".removerAtrasado", function(){
            var idx = $(this).data("index");
            
            // Também remove da lista de presentes, se estiver lá
            var atrasadoId = atrasadosPool[idx].id;
            atrasadosPresentesPool = atrasadosPresentesPool.filter(function(item) {
                return item.id != atrasadoId;
            });
            
            // Remove da lista de atrasados
            atrasadosPool.splice(idx, 1);
            
            atualizarAtrasadosPool();
            showToast("Jogador removido dos atrasados!");
        });
        
        // Marcar atrasado como presente
        $(document).on("click", ".marcarPresente", function(){
            var id = $(this).data("id");
            
            // Encontra o jogador na lista de atrasados
            var atrasado = atrasadosPool.find(function(item) {
                return item.id == id;
            });
            
            if (atrasado) {
                // Adiciona à lista de presentes
                atrasadosPresentesPool.push({
                    id: atrasado.id,
                    nome: atrasado.nome,
                    overall: atrasado.overall
                });
                
                atualizarAtrasadosPool();
                showToast("Atrasado marcado como presente!");
            }
        });
        
        // Remover presença do atrasado
        $(document).on("click", ".removerPresente", function(){
            var id = $(this).data("id");
            
            // Remove da lista de presentes
            atrasadosPresentesPool = atrasadosPresentesPool.filter(function(item) {
                return item.id != id;
            });
            
            atualizarAtrasadosPool();
            showToast("Presença removida!");
        });
        
        // Limpar todos os atrasados
        $("#limparAtrasadosBtn").click(function() {
            if (confirm("Tem certeza que deseja remover todos os jogadores atrasados?")) {
                atrasadosPool = [];
                atrasadosPresentesPool = [];
                atualizarAtrasadosPool();
                showToast("Todos os atrasados foram removidos!");
            }
        });
        
        // Salvar método de sorteio escolhido no localStorage
        $('input[name="metodo_sorteio"]').change(function() {
            localStorage.setItem("metodoSorteio", $(this).val());
        });
        
        // Antes de submeter o formulário, atualiza os campos ocultos
        $("#sortearForm").submit(function(){
            $("#avulsos_json").val(JSON.stringify(avulsosPool));
            $("#atrasados_json").val(JSON.stringify(atrasadosPool));
            $("#atrasados_presentes_json").val(JSON.stringify(atrasadosPresentesPool));
            return confirm("Tem certeza que deseja sortear os times?");
        });
    });
    </script>
</head>
<body>
<div class="container">
    <header class="panel">
        <h2><i class="fas fa-users"></i> Sorteador de Times</h2>
        <p><strong><i class="fas fa-calendar-alt"></i> Partida:</strong> <?= date("d/m/Y", strtotime($match['data'])) ?> - <strong><i class="fas fa-map-marker-alt"></i> Local:</strong> <?= htmlspecialchars($match['local']) ?></p>
        
        <?php if(!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?= $error ?>
            </div>
        <?php endif; ?>
    </header>
    
    <!-- Abas para organizar o conteúdo -->
    <div class="tabs">
        <div class="tab active" data-target="tab-jogadores"><i class="fas fa-user-friends"></i> Jogadores</div>
        <div class="tab" data-target="tab-avulsos"><i class="fas fa-user-plus"></i> Avulsos</div>
        <div class="tab" data-target="tab-atrasados"><i class="fas fa-clock"></i> Atrasados</div>
        <div class="tab" data-target="tab-sorteio"><i class="fas fa-random"></i> Sorteio</div>
        <?php if(isset($teams) && count($teams) > 0): ?>
        <div class="tab" data-target="tab-resultados"><i class="fas fa-trophy"></i> Resultados</div>
        <?php endif; ?>
    </div>
    
    <!-- Aba de Jogadores -->
    <div id="tab-jogadores" class="tab-content active">
        <div class="panel">
            <h3 class="section-title"><i class="fas fa-running"></i> Jogadores Confirmados (Campo)</h3>
            <div class="table-responsive">
                <table class="table" id="confirmedPlayersTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Overall</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($confirmedPlayers as $p): ?>
                        <tr>
                            <td><?= $p['id'] ?></td>
                            <td><?= htmlspecialchars($p['nome']) ?></td>
                            <td><?= $p['overall'] ?></td>
                            <td>
                                <button type="button" class="btn btn-warning btn-sm marcarAtrasado" data-id="<?= $p['id'] ?>" data-nome="<?= htmlspecialchars($p['nome']) ?>" data-overall="<?= $p['overall'] ?>">
                                    <i class="fas fa-clock"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h3 class="section-title mt-4"><i class="fas fa-hand-paper"></i> Goleiros Confirmados</h3>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($goalkeepers as $g): ?>
                        <tr>
                            <td><?= $g['id'] ?></td>
                            <td><?= htmlspecialchars($g['nome']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="panel">
            <h3 class="section-title"><i class="fas fa-list-ol"></i> Pool de Jogadores (Confirmados + Avulsos)</h3>
            <div class="info-box">
                <i class="fas fa-info-circle"></i> Esta lista mostra todos os jogadores disponíveis para o sorteio, ordenados por overall. Clique no ícone <i class="fas fa-clock"></i> para marcar um jogador como atrasado.
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nome</th>
                            <th>Overall</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody id="poolCompletoBody">
                        <!-- Conteúdo será preenchido via JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Aba de Avulsos -->
    <div id="tab-avulsos" class="tab-content">
        <div class="panel">
            <h3 class="section-title"><i class="fas fa-user-plus"></i> Adicionar Jogadores Avulsos</h3>
            <div class="info-box">
                <i class="fas fa-info-circle"></i> Jogadores avulsos são adicionados ao pool geral e distribuídos nos times conforme o método de sorteio escolhido.
            </div>
            
            <form id="addAvulsoForm" class="add-form">
                <input type="text" id="avulsoNome" class="form-control" placeholder="Nome do Avulso" required>
                <input type="number" step="0.1" id="avulsoOverall" class="form-control" placeholder="Nota (Overall)" required>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Adicionar
                </button>
            </form>
            
            <h3 class="section-title mt-4">Lista de Avulsos</h3>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Overall</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody id="avulsosTableBody">
                        <tr><td colspan="3">Nenhum avulso adicionado.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Aba de Atrasados -->
    <div id="tab-atrasados" class="tab-content">
        <div class="panel">
            <h3 class="section-title"><i class="fas fa-clock"></i> Jogadores Atrasados</h3>
            <div class="info-box">
                <i class="fas fa-info-circle"></i> Jogadores marcados como atrasados serão alocados prioritariamente no Time C. Quando chegarem, marque-os como presentes para incluí-los em um novo sorteio.
            </div>
            
            <div class="flex-between">
                <h4>Lista de Atrasados</h4>
                <button id="limparAtrasadosBtn" class="btn btn-danger btn-sm">
                    <i class="fas fa-trash"></i> Limpar Todos
                </button>
            </div>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Overall</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="atrasadosTableBody">
                        <tr><td colspan="4">Nenhum jogador marcado como atrasado.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Aba de Sorteio -->
    <div id="tab-sorteio" class="tab-content">
        <div class="panel">
            <h3 class="section-title"><i class="fas fa-random"></i> Método de Sorteio</h3>
            
            <div class="radio-group">
                <label class="radio-option">
                    <input type="radio" name="metodo_sorteio" value="certeiro" <?= ($metodoSorteioSalvo == 'certeiro') ? 'checked' : '' ?>> 
                    <span>Método Certeiro</span>
                </label>
                <label class="radio-option">
                    <input type="radio" name="metodo_sorteio" value="randomico" <?= ($metodoSorteioSalvo == 'randomico') ? 'checked' : '' ?>> 
                    <span>Método Randômico</span>
                </label>
                <label class="radio-option">
                    <input type="radio" name="metodo_sorteio" value="margem" <?= ($metodoSorteioSalvo == 'margem') ? 'checked' : '' ?>> 
                    <span>Método de Margem</span>
                </label>
            </div>
            
            <!-- Descrições dos métodos -->
            <div id="info-certeiro" class="method-description active">
                <h5><i class="fas fa-bullseye"></i> Método Certeiro</h5>
                <p>O método original que distribui os jogadores em ordem decrescente de overall, sempre escolhendo o time com menor pontuação total. Produz sempre os mesmos times para o mesmo conjunto de jogadores.</p>
            </div>
            <div id="info-randomico" class="method-description">
                <h5><i class="fas fa-dice"></i> Método Randômico</h5>
                <p>Faz até 100 sorteios completamente aleatórios e escolhe o melhor resultado (com menor diferença entre os times). Produz times diferentes a cada sorteio.</p>
            </div>
            <div id="info-margem" class="method-description">
                <h5><i class="fas fa-balance-scale"></i> Método de Margem</h5>
                <p>Similar ao método certeiro, mas em vez de sempre escolher o jogador com maior overall, seleciona aleatoriamente entre os 3 jogadores com maior overall disponíveis. Produz times equilibrados com alguma variação.</p>
            </div>
            
            <div class="info-box mt-4">
                <p><i class="fas fa-info-circle"></i> <strong>Atrasados:</strong> Jogadores marcados como atrasados serão alocados no Time C (se ainda não chegaram) ou distribuídos normalmente (se já estiverem presentes).</p>
                
                <p><i class="fas fa-users"></i> <strong>Total de jogadores para sorteio:</strong> <span id="total-players-count">0</span> 
                (<span id="players-count">0</span> confirmados + <span id="avulsos-count">0</span> avulsos - <span id="atrasados-count">0</span> atrasados)</p>
            </div>
            
            <!-- Botão para sortear times -->
            <form id="sortearForm" method="POST" action="">
                <input type="hidden" name="sortear" value="1">
                <input type="hidden" name="avulsos_json" id="avulsos_json" value="">
                <input type="hidden" name="atrasados_json" id="atrasados_json" value="">
                <input type="hidden" name="atrasados_presentes_json" id="atrasados_presentes_json" value="">
                <input type="hidden" name="metodo_sorteio" id="metodo_sorteio" value="<?= $metodoSorteioSalvo ?>">
                <script>
                    $(document).ready(function(){
                        // Atualiza o campo oculto quando o método muda
                        $('input[name="metodo_sorteio"]').change(function(){
                            $('#metodo_sorteio').val($(this).val());
                        });
                        
                        // Atualiza o contador de jogadores
                        function atualizarContadores() {
                            let confirmedCount = $("#confirmedPlayersTable tbody tr").length;
                            let avulsosCount = avulsosPool.length;
                            let atrasadosCount = 0;
                            
                            // Conta apenas atrasados que ainda não chegaram
                            atrasadosPool.forEach(function(atrasado) {
                                if (!isAtrasadoPresente(atrasado.id)) {
                                    atrasadosCount++;
                                }
                            });
                            
                            let totalCount = confirmedCount + avulsosCount;
                            
                            $("#players-count").text(confirmedCount);
                            $("#avulsos-count").text(avulsosCount);
                            $("#atrasados-count").text(atrasadosCount);
                            $("#total-players-count").text(totalCount);
                        }
                        
                        // Chama a função inicialmente e depois sempre que houver mudanças
                        atualizarContadores();
                        
                        // Adiciona evento para atualizar contadores
                        $(document).on("playerDataChanged", function() {
                            atualizarContadores();
                        });
                    });
                </script>
                <button type="submit" class="btn btn-secondary btn-lg w-100 mt-4">
                    <i class="fas fa-random"></i> Sortear Times
                </button>
            </form>
        </div>
    </div>
    
    <!-- Aba de Resultados (só aparece quando há resultados) -->
    <?php if(isset($teams) && count($teams) > 0): ?>
    <div id="tab-resultados" class="tab-content <?= isset($_POST['sortear']) ? 'active' : '' ?>">
        <div class="panel">
            <h3 class="section-title">
                <i class="fas fa-trophy"></i> Resultado do Sorteio 
                <?php if(!empty($metodoUsado)): ?>
                    <span class="badge badge-primary"><?= $metodoUsado ?></span>
                <?php endif; ?>
            </h3>
            
            <?php 
                $timeNames = ["Time A", "Time B", "Time C", "Time D"];
                $timeColors = ["team-header", "team-header team-b", "team-header team-c", "team-header team-d"];
                
                // Calcular e exibir estatísticas
                $teamTotals = [];
                for ($i = 0; $i < count($teams); $i++) {
                    $total = 0;
                    foreach ($teams[$i]['players'] as $player) {
                        $total += $player['overall'];
                    }
                    $teamTotals[$i] = $total;
                }
                
                $maxTotal = max($teamTotals);
                $minTotal = min($teamTotals);
                $diferenca = $maxTotal - $minTotal;
            ?>
            
            <div class="stats-box">
                <h5><i class="fas fa-chart-bar"></i> Estatísticas:</h5>
                <ul>
                    <li>Diferença de pontuação entre times: <strong><?= number_format($diferenca, 2) ?> pontos</strong></li>
                    <li>Time mais forte: <strong><?= number_format($maxTotal, 2) ?> pontos</strong></li>
                    <li>Time mais fraco: <strong><?= number_format($minTotal, 2) ?> pontos</strong></li>
                </ul>
            </div>
            
            <div class="grid-2">
                <?php 
                    for ($i = 0; $i < count($teams); $i++):
                        $players = $teams[$i]['players'];
                        if (count($players) > 0):
                ?>
                    <div class="team-card">
                        <div class="<?= $timeColors[$i] ?>">
                            <div><?= $timeNames[$i] ?> (<?= count($players) ?> jogadores)</div>
                            <div>Total: <?= number_format($teamTotals[$i], 2) ?> pontos</div>
                        </div>
                        <div class="team-body">
                            <ul class="player-list">
                                <?php foreach ($players as $pl): ?>
                                    <li class="player-item">
                                        <div><?= htmlspecialchars($pl['nome']) ?><?= (!empty($pl['avulso']) ? " <span class='badge badge-secondary'>Avulso</span>" : "") ?></div>
                                        <div><?= $pl['overall'] ?></div>
                                    </li>
                                <?php endforeach; ?>
                                <?php if(isset($teams[$i]['goalkeeper'])): 
                                    $gk = $teams[$i]['goalkeeper']; ?>
                                    <li class="player-item goalkeeper">
                                        <div><i class="fas fa-hand-paper"></i> <?= htmlspecialchars($gk['nome']) ?></div>
                                        <div>Goleiro</div>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                <?php 
                        endif;
                    endfor; 
                ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="panel text-center mt-4">
        <a href="dashboard.php" class="btn btn-primary">
            <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
        </a>
    </div>
</div>

<script>
    // Inicialização adicional
    $(document).ready(function(){
        // Se houver resultados, mostra a aba de resultados inicialmente após sortear
        <?php if(isset($_POST['sortear']) && isset($teams) && count($teams) > 0): ?>
        $(".tab").removeClass("active");
        $(".tab[data-target='tab-resultados']").addClass("active");
        $(".tab-content").removeClass("active");
        $("#tab-resultados").addClass("active");
        <?php endif; ?>
        
        // Se não há jogadores, mostra mensagem
        if ($("#confirmedPlayersTable tbody tr").length === 0) {
            $("#confirmedPlayersTable tbody").html('<tr><td colspan="4">Nenhum jogador confirmado encontrado.</td></tr>');
        }
        
        // Se não há goleiros, mostra mensagem
        if ($(".goleiro-item").length === 0) {
            $(".goleiros-container tbody").html('<tr><td colspan="2">Nenhum goleiro confirmado encontrado.</td></tr>');
        }
        
        // Exibe mensagem após sorteio bem-sucedido
        <?php if(isset($_POST['sortear']) && isset($teams) && count($teams) > 0): ?>
        showToast("Times sorteados com sucesso!");
        <?php endif; ?>
        
        // Exibe mensagem de erro se houver
        <?php if(!empty($error)): ?>
        showToast("<?= $error ?>", true);
        <?php endif; ?>
    });
</script>
</body>
</html>