<?php
    session_start();
    require 'config.php';
    
    // Verifica se o usuário é admin ou admin_master
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['tipo_usuario'], ['admin', 'admin_master'])) {
        header("Location: login.php");
        exit();
    }
    
    // Verifica se o usuário está logado
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
    
    $user_id = $_SESSION['user_id'];
    $nome = $_SESSION['nome'] ?? 'Usuário';
    $tipo_usuario = $_SESSION['tipo_usuario'] ?? 'comum';
    $is_admin = in_array($tipo_usuario, ['admin', 'admin_master']);
    
    // Busca a foto de perfil (ou define a padrão)
    $foto_perfil = "uploads/avatar_padrao.svg";
    $stmt = $conn->prepare("SELECT foto_perfil FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if (!empty($row['foto_perfil'])) {
            $foto_perfil = $row['foto_perfil'];
        }
    }
    $stmt->close();
    
    // Obtém a partida do dia (se houver)
    $stmt = $conn->prepare("SELECT id, data, local, status_edicao, ultima_atualizacao FROM matches WHERE data = CURDATE() AND status != 'cancelada' LIMIT 1");
    $stmt->execute();
    $partida = $stmt->get_result()->fetch_assoc();
    
    if (!$partida) {
        $erro_message = "Não existem estatísticas para inserir hoje porque não há partidas agendadas para a data atual.";
        $redirect_url = "dashboard.php";
        include 'error_template.php';
        exit();
    }
    
    $match_id = $partida['id'];
    $status_edicao = $partida['status_edicao'];
    $ultima_atualizacao = $partida['ultima_atualizacao'] ? date("d/m/Y H:i", strtotime($partida['ultima_atualizacao'])) : "Nenhuma atualização ainda";
    $edicao_liberada = ($status_edicao == 'livre' || $is_admin);
    
    // Obtém os jogadores confirmados na lista de presença, se necessário
    $mostrar_confirmados = isset($_GET['mostrar_confirmados']) ? boolval($_GET['mostrar_confirmados']) : false;
    
    $sql_jogadores = "
        SELECT u.id, u.nome, u.foto_perfil, COALESCE(s.gols, 0) AS gols, COALESCE(s.assistencias, 0) AS assistencias, 
               COALESCE(s.gols_sofridos, 0) AS gols_sofridos
        FROM users u
        LEFT JOIN player_match_statistics s ON u.id = s.player_id AND s.match_id = ?
    ";
    
    // Obtém estatísticas já salvas para a partida selecionada
    $estatisticas = [];
    if ($match_id) {
        $stats_stmt = $conn->prepare("
            SELECT player_id, gols, assistencias, gols_sofridos 
            FROM player_match_statistics 
            WHERE match_id = ?
        ");
        $stats_stmt->bind_param("i", $match_id);
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        
        while ($row = $stats_result->fetch_assoc()) {
            $estatisticas[$row['player_id']] = $row;
        }
    }
    
    if ($mostrar_confirmados) {
        $sql_jogadores .= " INNER JOIN match_presence p ON u.id = p.user_id WHERE p.match_id = ? AND p.status = 'confirmado'";
    } else {
        $sql_jogadores .= " WHERE 1=1"; // Evita erro de sintaxe ao concatenar mais condições
    }
    
    // Adiciona ordenação por nome
    $sql_jogadores .= " ORDER BY u.nome ASC";
    
    $stmt = $conn->prepare($sql_jogadores);
    if ($mostrar_confirmados) {
        $stmt->bind_param("ii", $match_id, $match_id);
    } else {
        $stmt->bind_param("i", $match_id);
    }
    
    $stmt->execute();
    $jogadores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Registrar Estatísticas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #ADFF2F;
            --primary-dark: #94D82A;
            --primary-darker: #78B023;
            --secondary-color: #346900;
            --background-color: #1C1C1C;
            --card-color: #2A2A2A;
            --text-color: #FFFFFF;
            --text-muted: #BBBBBB;
            --accent-color: #F5D105;
            --danger-color: #F44336;
            --success-color: #4CAF50;
            --warning-color: #FFC107;
            --border-radius: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: var(--background-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 0;
            margin: 0;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            -webkit-tap-highlight-color: transparent;
        }
        
        /* Header */
        .app-header {
            background-color: var(--card-color);
            padding: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .profile-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }
        
        .user-info {
            text-align: left;
        }
        
        .username {
            color: var(--text-color);
            font-weight: 600;
            font-size: 16px;
            margin: 0;
        }
        
        .page-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        /* Main Container */
        .main-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 15px;
        }
        
        /* Cards */
        .card {
            background: var(--card-color);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        
        /* Match Info */
        .match-info {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 20px;
        }
        
        .match-header {
            background-color: var(--primary-color);
            color: var(--background-color);
            padding: 12px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            margin: -20px -20px 15px -20px;
        }
        
        .match-date {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            margin-bottom: 8px;
        }
        
        .info-item i {
            color: var(--primary-color);
            font-size: 18px;
            width: 24px;
            text-align: center;
        }
        
        .edit-toggle {
            background-color: var(--accent-color);
            color: var(--background-color);
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            transition: all 0.2s ease;
            margin-top: 10px;
        }
        
        .edit-toggle:hover {
            background-color: #e0be04;
        }
        
        /* Filter Option */
        .filter-option {
            display: flex;
            align-items: center;
            gap: 10px;
            background-color: rgba(255, 255, 255, 0.05);
            padding: 12px;
            border-radius: 8px;
            margin: 15px 0;
        }
        
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
            background-color: #444;
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
            background-color: var(--primary-color);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        /* Player Stats Table */
        .stats-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            overflow: hidden;
            border-radius: 8px;
            table-layout: fixed; /* Garante larguras iguais para colunas especificadas */
        }
        
        .stats-table th {
            background-color: var(--primary-color);
            color: var(--background-color);
            padding: 12px 10px;
            font-weight: 600;
            text-align: center;
            font-size: 14px;
        }
        
        .stats-table th:first-child {
            width: 40%; /* Largura para coluna de nome do jogador */
        }
        
        .stats-table th:not(:first-child) {
            width: 20%; /* Largura igual para as colunas de estatísticas */
        }
        
        .stats-table td {
            padding: 12px 8px;
            text-align: center;
            background-color: #333;
            border-bottom: 1px solid #444;
        }
        
        .stats-table tr:last-child td {
            border-bottom: none;
        }
        
        .stats-table tr:nth-child(even) td {
            background-color: #3a3a3a;
        }
        
        .player-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
            padding-left: 10px !important;
        }
        
        .player-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }
        
        /* Stat Control */
        .stat-control {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 0 2px;
        }
        
        .stat-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: none;
            background-color: #444;
            color: white;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .stat-btn:hover {
            background-color: #555;
        }
        
        .stat-value {
            font-weight: 600;
            font-size: 16px;
            background-color: rgba(255, 255, 255, 0.1);
            padding: 2px 8px;
            border-radius: 4px;
            min-width: 30px;
            text-align: center;
            display: block;
            width: 100%;
        }
        
        /* Save Button */
        .save-btn {
            background-color: var(--primary-color);
            color: var(--background-color);
            border: none;
            padding: 15px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            transition: all 0.2s ease;
            margin: 20px 0 10px;
        }
        
        .save-btn:hover {
            background-color: var(--primary-darker);
        }
        
        .back-btn {
            background-color: #444;
            color: white;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            transition: all 0.2s ease;
            margin-top: 10px;
        }
        
        .back-btn:hover {
            background-color: #555;
        }
        
        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--success-color);
            color: white;
            padding: 12px 25px;
            border-radius: 8px;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            font-weight: 500;
            animation: slideDown 0.3s ease-out forwards;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translate(-50%, -20px);
            }
            to {
                opacity: 1;
                transform: translate(-50%, 0);
            }
        }
        
        /* Responsive */
        @media (max-width: 600px) {
            .stats-table th, .stats-table td {
                padding: 8px 3px;
                font-size: 13px;
            }
            
            .stat-btn {
                width: 24px;
                height: 24px;
                font-size: 12px;
            }
            
            .stat-value {
                min-width: 22px;
                font-size: 13px;
                padding: 2px 4px;
            }
            
            .player-avatar {
                width: 24px;
                height: 24px;
                border-width: 1px;
            }
            
            .player-cell {
                gap: 5px;
                font-size: 13px;
            }
            
            .stats-table th {
                font-size: 12px;
                white-space: nowrap;
            }
            
            .stats-table th:first-child {
                width: 35%; /* Ajusta para telas menores */
            }
            
            .main-container {
                padding: 8px;
            }
            
            .card {
                padding: 15px;
            }
            
            .save-btn, .back-btn {
                padding: 12px 15px;
                font-size: 14px;
            }
        }
        
        /* Estilos para o cabeçalho fixo da tabela */
        .stats-container {
            position: relative;
            margin-top: 20px;
            border-radius: 8px;
            overflow: visible;
        }
        
        .table-header-container {
            position: relative;
            z-index: 20;
        }
        
        /* Estilizando o cabeçalho da tabela */
        .stats-header-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
            border-radius: 8px 8px 0 0;
            overflow: hidden;
        }
        
        .stats-header-table th {
            padding: 12px 10px;
            font-weight: 600;
            text-align: center;
            font-size: 14px;
        }
        
        /* Cores específicas do cabeçalho */
        .stats-header-table th:nth-child(1) {
            padding-top: 20px;
            background-color: #ADFF2F; /* Verde original */
            color: #1C1C1C;
            width: 40%;
            text-align: left;
            padding-left: 20px; /* Alinhamento com o conteúdo da célula de dados */
        }
        
        .stats-header-table th:nth-child(2) {
            padding-top: 20px;
            background-color: #4CAF50; /* Verde para gols */
            color: #1C1C1C;
            width: 20%;
        }
        
        .stats-header-table th:nth-child(3) {
            padding-top: 20px;
            background-color: #2196F3; /* Azul para assistências */
            color: #1C1C1C;
            width: 20%;
        }
        
        .stats-header-table th:nth-child(4) {
            padding-top: 20px;
            background-color: #FF5722; /* Laranja para gols sofridos */
            color: #1C1C1C;
            width: 20%;
        }
        
        /* Tabela do corpo */
        .stats-body-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed; /* Importante para larguras consistentes */
        }
        
        .stats-body-table td {
            padding: 12px 8px;
            text-align: center;
            background-color: #333;
            border-bottom: 1px solid #444;
        }
        
        /* Definindo as mesmas larguras exatas */
        .stats-body-table td:nth-child(1) {
            width: 40%;
            padding-left: 10px;
            padding-right: 10px;
        }
        
        .stats-body-table td:nth-child(2),
        .stats-body-table td:nth-child(3),
        .stats-body-table td:nth-child(4) {
            width: 20%;
        }
        
        /* Indicadores visuais para as colunas */
        .stats-body-table td:nth-child(2) {
            border-left: 3px solid rgba(76, 175, 80, 0.3); /* Verde para gols */
        }
        
        .stats-body-table td:nth-child(3) {
            border-left: 3px solid rgba(33, 150, 243, 0.3); /* Azul para assistências */
        }
        
        .stats-body-table td:nth-child(4) {
            border-left: 3px solid rgba(255, 87, 34, 0.3); /* Laranja para gols sofridos */
        }
        
        /* Estilos para células alternadas */
        .stats-body-table tr:nth-child(even) td {
            background-color: #3a3a3a;
        }
        
        .stats-body-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Estilo para a célula do jogador */
        .player-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
        }
        
        .player-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
            flex-shrink: 0; /* Evita que a imagem encolha */
        }
        
        /* Classe aplicada quando o cabeçalho fica fixo */
        .fixed-header {
            position: fixed;
            top: 60px; /* Altura do header da aplicação */
            left: 50%;
            transform: translateX(-50%);
            max-width: 770px; /* Ajustar para a largura do container principal menos padding */
            width: calc(100% - 30px);
            z-index: 50;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        /* Espaço reservado quando o cabeçalho fica fixo */
        .header-placeholder {
            display: none;
            height: 0;
        }
        
        .header-placeholder.active {
            display: block;
            height: 42px; /* Ajuste para a altura exata do cabeçalho */
        }
        
        /* Adaptações para mobile */
        @media (max-width: 600px) {
            .stats-header-table th,
            .stats-body-table td {
                padding: 8px 3px;
                font-size: 13px;
            }
            
            .stats-header-table th:nth-child(1) {
                padding-left: 10px;
            }
            
            .fixed-header {
                top: 55px; /* Altura do header em mobile */
                max-width: calc(100% - 16px);
                width: calc(100% - 16px);
            }
            
            .header-placeholder.active {
                height: 38px; /* Altura do cabeçalho em mobile */
            }
            
            /* Ajustar proporções em mobile */
            .stats-header-table th:nth-child(1),
            .stats-body-table td:nth-child(1) {
                width: 35%;
            }
            
            .stats-header-table th:nth-child(2),
            .stats-header-table th:nth-child(3),
            .stats-header-table th:nth-child(4),
            .stats-body-table td:nth-child(2),
            .stats-body-table td:nth-child(3),
            .stats-body-table td:nth-child(4) {
                width: calc(65% / 3);
            }
            
            .player-avatar {
                width: 24px;
                height: 24px;
            }
        }
        
    </style>
</head>
<body>
    <!-- Header da Aplicação -->
    <header class="app-header">
        <a href="perfil.php" class="user-profile">
            <img src="<?= htmlspecialchars($foto_perfil) ?>" alt="Foto de Perfil" class="profile-photo">
            <div class="user-info">
                <p class="username"><?= htmlspecialchars($nome) ?></p>
            </div>
        </a>
        <div class="page-title">Registrar Estatísticas</div>
        <a href="dashboard.php" style="color: var(--text-muted); text-decoration: none;">
            <i class="fas fa-home"></i>
        </a>
    </header>

    <div class="main-container">
        <!-- Card Principal -->
        <div class="card">
            <div class="match-header">
                <i class="fas fa-futbol"></i> PARTIDA DO DIA
            </div>
            
            <div class="match-date">
                <?= date("d/m/Y", strtotime($partida['data'])); ?>
            </div>
            
            <div class="match-info">
                <div class="info-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <span><strong>Local:</strong> <?= htmlspecialchars($partida['local']); ?></span>
                </div>
                
                <div class="info-item">
                    <i class="fas <?= $status_edicao == 'livre' ? 'fa-unlock' : 'fa-lock'; ?>"></i>
                    <span><strong>Status de Edição:</strong> <?= $status_edicao == 'livre' ? 'Livre para todos' : 'Restrito a administradores'; ?></span>
                </div>
                
                <?php if ($partida['ultima_atualizacao']): ?>
                <div class="info-item">
                    <i class="fas fa-clock"></i>
                    <span><strong>Última atualização:</strong> <?= $ultima_atualizacao; ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($is_admin): ?>
                <button class="edit-toggle" onclick="alternarModoEdicao(<?= $match_id; ?>)">
                    <i class="fas fa-sync-alt"></i> Alternar Modo de Edição
                </button>
            <?php endif; ?>
            
            <!-- Filtro de jogadores -->
            <div class="filter-option">
                <label class="switch">
                    <input type="checkbox" id="filtroConfirmados" <?= $mostrar_confirmados ? 'checked' : ''; ?>>
                    <span class="slider"></span>
                </label>
                <span>Mostrar apenas jogadores confirmados</span>
            </div>
            
            <!-- Formulário de estatísticas -->
            <form id="estatisticasForm">
                <input type="hidden" name="match_id" value="<?= $match_id; ?>">
                
                <div class="stats-container">
                    <!-- Tabela de cabeçalho -->
                    <div class="table-header-container" id="tableHeaderContainer">
                        <table class="stats-header-table" id="statsHeaderTable">
                            <thead>
                                <tr>
                                    <th>Jogador</th>
                                    <th>⚽ Gols</th>
                                    <th>🤵‍♂️ Assists.</th>
                                    <th>🥅 Sofridos</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                    
                    <!-- Espaço que aparece quando o cabeçalho fica fixo -->
                    <div class="header-placeholder" id="headerPlaceholder"></div>
                    
                    <!-- Tabela de dados -->
                    <table class="stats-body-table">
                        <tbody>
                            <?php foreach ($jogadores as $jogador): 
                                $avatar = !empty($jogador['foto_perfil']) ? $jogador['foto_perfil'] : 'uploads/avatar_padrao.svg';
                                $dados = $estatisticas[$jogador['id']] ?? ['gols' => 0, 'assistencias' => 0, 'gols_sofridos' => 0];
                            ?>
                                <tr>
                                    <td>
                                        <div class="player-cell">
                                        <img src="<?= htmlspecialchars($avatar) ?>" alt="Avatar" class="player-avatar">
                                            <span>
                                            <?php
                                                $nome_completo = $jogador['nome'];
                                                $partes_nome = explode(' ', $nome_completo);
                                                $primeiro_nome = $partes_nome[0];
                                                $abreviado = $primeiro_nome;
                                                
                                                // Se tiver sobrenome, adiciona a primeira letra com ponto
                                                if (count($partes_nome) > 1) {
                                                    $segunda_parte = $partes_nome[1];
                                                    $abreviado .= ' ' . substr($segunda_parte, 0, 1) . '.';
                                                }
                                                
                                                echo htmlspecialchars($abreviado);
                                            ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="stat-control">
                                            <button type="button" class="stat-btn" onclick="alterarValor(<?= $jogador['id']; ?>, 'gols', 1)">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                            <span class="stat-value" id="gols-<?= $jogador['id']; ?>"><?= $dados['gols']; ?></span>
                                            <button type="button" class="stat-btn" onclick="alterarValor(<?= $jogador['id']; ?>, 'gols', -1)">
                                                <i class="fas fa-minus"></i>
                                            </button>
                                            <input type="hidden" name="jogadores[<?= $jogador['id'] ?>][gols]" id="input-gols-<?= $jogador['id']; ?>" value="<?= $dados['gols']; ?>">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="stat-control">
                                            <button type="button" class="stat-btn" onclick="alterarValor(<?= $jogador['id']; ?>, 'assistencias', 1)">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                            <span class="stat-value" id="assistencias-<?= $jogador['id']; ?>"><?= $dados['assistencias']; ?></span>
                                            <button type="button" class="stat-btn" onclick="alterarValor(<?= $jogador['id']; ?>, 'assistencias', -1)">
                                                <i class="fas fa-minus"></i>
                                            </button>
                                            <input type="hidden" name="jogadores[<?= $jogador['id'] ?>][assistencias]" id="input-assistencias-<?= $jogador['id']; ?>" value="<?= $dados['assistencias']; ?>">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="stat-control">
                                            <button type="button" class="stat-btn" onclick="alterarValor(<?= $jogador['id']; ?>, 'gols_sofridos', 1)">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                            <span class="stat-value" id="gols_sofridos-<?= $jogador['id']; ?>"><?= $dados['gols_sofridos']; ?></span>
                                            <button type="button" class="stat-btn" onclick="alterarValor(<?= $jogador['id']; ?>, 'gols_sofridos', -1)">
                                                <i class="fas fa-minus"></i>
                                            </button>
                                            <input type="hidden" name="jogadores[<?= $jogador['id'] ?>][gols_sofridos]" id="input-gols_sofridos-<?= $jogador['id']; ?>" value="<?= $dados['gols_sofridos']; ?>">
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <button type="button" class="save-btn" onclick="salvarEstatisticas()">
                    <i class="fas fa-save"></i> Salvar Estatísticas
                </button>
                
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                </a>
            </form>
        </div>
    </div>

    <script>
        function alterarValor(playerId, campo, incremento) {
            let span = document.getElementById(campo + "-" + playerId);
            let input = document.getElementById("input-" + campo + "-" + playerId);
    
            if (span && input) {
                let valorAtual = parseInt(span.innerText);
                let novoValor = valorAtual + incremento;
    
                // Garante que os valores não sejam negativos
                if (novoValor < 0) {
                    novoValor = 0;
                }
    
                // Atualiza a interface com o novo valor
                span.innerText = novoValor;
                input.value = novoValor;
            }
        }
    
        function alternarModoEdicao(matchId) {
            $.ajax({
                url: "atualizar_modo_edicao.php",
                type: "POST",
                data: { match_id: matchId },
                success: function(response) {
                    // Criar notificação de sucesso
                    const notificacao = document.createElement('div');
                    notificacao.className = 'notification';
                    notificacao.innerHTML = '<i class="fas fa-check-circle"></i> Modo de edição alterado com sucesso!';
                    document.body.appendChild(notificacao);
                    
                    // Remover notificação após 2 segundos
                    setTimeout(() => {
                        notificacao.style.opacity = '0';
                        setTimeout(() => {
                            notificacao.remove();
                            location.reload(); // Recarrega a página
                        }, 300);
                    }, 1500);
                },
                error: function() {
                    alert("Erro ao alternar o modo de edição.");
                }
            });
        }
    
        document.addEventListener("DOMContentLoaded", function() {
            const filtroCheckbox = document.getElementById("filtroConfirmados");

            if (filtroCheckbox) {
                filtroCheckbox.addEventListener("change", function() {
                    const params = new URLSearchParams(window.location.search);

                    if (this.checked) {
                        params.set("mostrar_confirmados", "true");
                    } else {
                        params.delete("mostrar_confirmados"); // Remove o filtro se desmarcado
                    }

                    window.location.search = params.toString();
                });
            }
        });
    
        function salvarEstatisticas() {
            let formData = $("#estatisticasForm").serialize();
    
            $.ajax({
                url: "processar_registrar_estatisticas.php",
                type: "POST",
                data: formData,
                success: function(response) {
                    // Criar notificação de sucesso
                    const notificacao = document.createElement('div');
                    notificacao.className = 'notification';
                    notificacao.innerHTML = '<i class="fas fa-check-circle"></i> Estatísticas salvas com sucesso!';
                    document.body.appendChild(notificacao);
                    
                    // Remover notificação após 2 segundos
                    setTimeout(() => {
                        notificacao.style.opacity = '0';
                        setTimeout(() => {
                            notificacao.remove();
                            location.reload(); // Recarrega a página
                        }, 300);
                    }, 1500);
                },
                error: function() {
                    alert("Erro ao salvar estatísticas.");
                }
            });
        }
    </script>

    /* Script para o cabeçalho fixo */
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const headerContainer = document.getElementById('tableHeaderContainer');
        const headerTable = document.getElementById('statsHeaderTable');
        const headerPlaceholder = document.getElementById('headerPlaceholder');
        const appHeader = document.querySelector('.app-header');
        
        if (!headerContainer || !headerTable || !headerPlaceholder || !appHeader) {
            console.error('Um ou mais elementos necessários não foram encontrados');
            return;
        }
        
        // Ajustar larguras para garantir alinhamento perfeito
        function ajustarLarguras() {
            const bodyTable = document.querySelector('.stats-body-table');
            if (!bodyTable) return;
            
            // Garantir que as larguras sejam exatamente iguais
            const bodyWidths = [];
            const headerCells = headerTable.querySelectorAll('th');
            const firstRow = bodyTable.querySelector('tr');
            
            if (firstRow) {
                const bodyCells = firstRow.querySelectorAll('td');
                bodyCells.forEach((cell, index) => {
                    bodyWidths[index] = cell.offsetWidth;
                });
                
                headerCells.forEach((cell, index) => {
                    if (bodyWidths[index]) {
                        cell.style.width = bodyWidths[index] + 'px';
                    }
                });
            }
        }
        
        // Obtém a posição original do cabeçalho
        const headerRect = headerContainer.getBoundingClientRect();
        const originalTop = headerRect.top + window.scrollY;
        const appHeaderHeight = appHeader.offsetHeight;
        
        // Função para verificar a posição e fazer o cabeçalho fixo
        function checkHeaderPosition() {
            const scrollTop = window.scrollY;
            
            // Se rolou para além da posição original do cabeçalho
            if (scrollTop > originalTop - appHeaderHeight) {
                headerTable.classList.add('fixed-header');
                headerPlaceholder.classList.add('active');
            } else {
                headerTable.classList.remove('fixed-header');
                headerPlaceholder.classList.remove('active');
            }
        }
        
        // Executar ajuste inicial após um pequeno atraso para permitir renderização completa
        setTimeout(ajustarLarguras, 100);
        
        // Verificar posição e adicionar eventos
        checkHeaderPosition();
        window.addEventListener('scroll', checkHeaderPosition);
        window.addEventListener('resize', function() {
            ajustarLarguras();
            checkHeaderPosition();
        });
    });
    </script>

</body>
</html>