<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : 404 error view - Zero-scroll Light Theme Studio UI
 */
$message = $message ?? 'The page you are looking for does not exist or has been moved.';
?>

<div class="cyber-404-viewport">
    <!-- Animated Light Blueprint Grid & Horizon Line -->
    <div class="cyber-grid-canvas" aria-hidden="true"></div>
    <div class="cyber-scan-laser" aria-hidden="true"></div>

    <!-- Soft Ambient Light Nebulas -->
    <div class="cyber-nebula nebula-indigo" aria-hidden="true"></div>
    <div class="cyber-nebula nebula-cyan" aria-hidden="true"></div>
    <div class="cyber-nebula nebula-rose" aria-hidden="true"></div>

    <!-- Floating Micro Sparkles -->
    <div class="cyber-stars" aria-hidden="true">
        <span class="star s1"></span>
        <span class="star s2"></span>
        <span class="star s3"></span>
        <span class="star s4"></span>
        <span class="star s5"></span>
        <span class="star s6"></span>
    </div>

    <!-- Centered Content Stage -->
    <div class="cyber-stage">
        <!-- Status Kicker Pill -->
        <div class="cyber-badge">
            <span class="cyber-ping-dot"></span>
            <span class="cyber-badge-text">SIGNAL LOST &bull; 404 ENDPOINT UNREACHABLE</span>
        </div>

        <!-- 3D Holographic 404 Centerpiece with Gyroscope -->
        <div class="cyber-hero">
            <div class="cyber-digit digit-left">4</div>

            <div class="cyber-gyro-portal" title="Quantum Router">
                <div class="gyro-ring gyro-ring-1"></div>
                <div class="gyro-ring gyro-ring-2"></div>
                <div class="gyro-ring gyro-ring-3"></div>
                <div class="gyro-core">
                    <i class="fas fa-cube"></i>
                </div>
                <div class="gyro-particle"></div>
            </div>

            <div class="cyber-digit digit-right">4</div>
        </div>

        <!-- Typography -->
        <h1 class="cyber-title">Lost in the Data Void</h1>
        <p class="cyber-desc"><?= e($message) ?></p>

        <!-- Action Buttons -->
        <div class="cyber-actions">
            <a href="<?= e(url()) ?>" class="cyber-btn cyber-btn-primary">
                <i class="fas fa-satellite"></i>
                <span>Return to Dashboard</span>
                <i class="fas fa-arrow-right cyber-btn-arrow"></i>
            </a>
            <button type="button" class="cyber-btn cyber-btn-secondary" onclick="window.history.back()">
                <i class="fas fa-arrow-left"></i>
                <span>Previous Page</span>
            </button>
        </div>

        <!-- Quick Access Module Chips -->
        <div class="cyber-dock">
            <div class="cyber-dock-label"><i class="fas fa-compass"></i> Direct Module Relink:</div>
            <div class="cyber-dock-links">
                <a href="<?= e(url()) ?>" class="cyber-chip">
                    <i class="fas fa-layer-group"></i>
                    <span>All Reports</span>
                </a>
                <a href="<?= e(url('sales')) ?>" class="cyber-chip">
                    <i class="fas fa-chart-line"></i>
                    <span>Sales Hub</span>
                </a>
                <a href="<?= e(url('reports/purchase-order')) ?>" class="cyber-chip">
                    <i class="fas fa-file-invoice"></i>
                    <span>Purchase Orders</span>
                </a>
                <a href="<?= e(url('production')) ?>" class="cyber-chip">
                    <i class="fas fa-industry"></i>
                    <span>Production</span>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
/* Absolute Zero-Scroll Full Viewport Lock - Light Theme */
html, body {
    width: 100vw !important;
    height: 100vh !important;
    max-height: 100vh !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    background: #f8fafc !important;
}

body .wrap,
body.rpt-body,
.wrap {
    width: 100vw !important;
    max-width: 100vw !important;
    height: 100vh !important;
    max-height: 100vh !important;
    padding: 0 !important;
    margin: 0 !important;
    overflow: hidden !important;
    background: transparent !important;
}

/* Master Viewport Container - Light Porcelain Theme */
.cyber-404-viewport {
    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    max-height: 100vh;
    overflow: hidden !important;
    display: flex;
    align-items: center;
    justify-content: center;
    background: radial-gradient(circle at 50% 25%, #ffffff 0%, #f1f5f9 60%, #e2e8f0 100%);
    font-family: 'Inter', -apple-system, sans-serif;
    color: #0f172a;
    z-index: 999999;
    user-select: none;
}

/* Subtle Blueprint Grid */
.cyber-grid-canvas {
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(99, 102, 241, 0.06) 1px, transparent 1px),
        linear-gradient(90deg, rgba(99, 102, 241, 0.06) 1px, transparent 1px);
    background-size: 46px 46px;
    background-position: center center;
    mask-image: radial-gradient(ellipse at 50% 50%, rgba(0,0,0,1) 25%, rgba(0,0,0,0) 80%);
    -webkit-mask-image: radial-gradient(ellipse at 50% 50%, rgba(0,0,0,1) 25%, rgba(0,0,0,0) 80%);
    pointer-events: none;
}

/* Scanning Horizontal Laser Beam (Indigo/Cyan Accent) */
.cyber-scan-laser {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent 0%, rgba(67, 56, 202, 0.6) 50%, transparent 100%);
    box-shadow: 0 0 14px rgba(99, 102, 241, 0.5);
    opacity: 0.5;
    animation: laserScan 6s linear infinite;
    pointer-events: none;
}

@keyframes laserScan {
    0% { transform: translateY(-50px); opacity: 0; }
    15% { opacity: 0.6; }
    85% { opacity: 0.6; }
    100% { transform: translateY(105vh); opacity: 0; }
}

/* Soft Ambient Light Nebulas */
.cyber-nebula {
    position: absolute;
    border-radius: 50%;
    filter: blur(95px);
    pointer-events: none;
    opacity: 0.6;
}

.nebula-indigo {
    width: 520px;
    height: 520px;
    background: radial-gradient(circle, rgba(199, 210, 254, 0.7) 0%, transparent 70%);
    top: 8%;
    left: 18%;
    animation: driftNebula1 12s ease-in-out infinite alternate;
}

.nebula-cyan {
    width: 440px;
    height: 440px;
    background: radial-gradient(circle, rgba(186, 230, 253, 0.65) 0%, transparent 70%);
    bottom: 10%;
    right: 18%;
    animation: driftNebula2 14s ease-in-out infinite alternate;
}

.nebula-rose {
    width: 340px;
    height: 340px;
    background: radial-gradient(circle, rgba(254, 205, 211, 0.5) 0%, transparent 70%);
    top: 40%;
    right: 30%;
    animation: driftNebula3 18s ease-in-out infinite alternate;
}

@keyframes driftNebula1 {
    0% { transform: translate(0, 0) scale(1); }
    100% { transform: translate(35px, -20px) scale(1.12); }
}

@keyframes driftNebula2 {
    0% { transform: translate(0, 0) scale(1); }
    100% { transform: translate(-30px, 25px) scale(1.1); }
}

@keyframes driftNebula3 {
    0% { transform: translate(0, 0) scale(0.95); }
    100% { transform: translate(20px, -30px) scale(1.15); }
}

/* Floating Micro Sparkles */
.cyber-stars {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.star {
    position: absolute;
    border-radius: 50%;
    background: #6366f1;
    box-shadow: 0 0 6px rgba(99, 102, 241, 0.6);
    animation: starTwinkle 3s infinite ease-in-out;
}

.s1 { top: 18%; left: 15%; width: 4px; height: 4px; animation-duration: 2.8s; background: #4f46e5; }
.s2 { top: 72%; left: 22%; width: 3px; height: 3px; animation-duration: 3.6s; animation-delay: 0.8s; background: #0284c7; }
.s3 { top: 25%; right: 18%; width: 5px; height: 5px; animation-duration: 4.2s; animation-delay: 1.4s; background: #6366f1; }
.s4 { top: 80%; right: 26%; width: 3px; height: 3px; animation-duration: 3.1s; animation-delay: 0.3s; background: #ec4899; }
.s5 { top: 40%; left: 8%; width: 3px; height: 3px; animation-duration: 2.5s; animation-delay: 2s; background: #0ea5e9; }
.s6 { top: 12%; right: 42%; width: 4px; height: 4px; animation-duration: 3.9s; animation-delay: 1.1s; background: #818cf8; }

@keyframes starTwinkle {
    0%, 100% { opacity: 0.25; transform: scale(0.85); }
    50% { opacity: 0.85; transform: scale(1.3); }
}

/* Centered Stage */
.cyber-stage {
    position: relative;
    z-index: 10;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    max-width: 640px;
    width: 90%;
    padding: 24px 20px;
    animation: stageFadeIn 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes stageFadeIn {
    0% { opacity: 0; transform: translateY(20px) scale(0.96); }
    100% { opacity: 1; transform: translateY(0) scale(1); }
}

/* Status Badge - Frosted Light Glass */
.cyber-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid #c7d2fe;
    box-shadow: 0 4px 14px rgba(67, 56, 202, 0.08);
    margin-bottom: 18px;
}

.cyber-ping-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #0284c7;
    box-shadow: 0 0 10px #0284c7;
    animation: pingDot 1.6s infinite;
}

@keyframes pingDot {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.35); opacity: 0.5; box-shadow: 0 0 14px #0284c7; }
}

.cyber-badge-text {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #4338ca;
    text-transform: uppercase;
}

/* 404 Hero Display */
.cyber-hero {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin-bottom: 6px;
}

.cyber-digit {
    font-family: 'Outfit', sans-serif;
    font-size: 112px;
    font-weight: 800;
    line-height: 1;
    background: linear-gradient(180deg, #0f172a 0%, #1e1b4b 45%, #4338ca 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    filter: drop-shadow(0 10px 20px rgba(67, 56, 202, 0.15));
    letter-spacing: -0.04em;
    animation: floatDigit 3.5s ease-in-out infinite alternate;
}

.digit-left { animation-delay: 0s; }
.digit-right { animation-delay: 0.5s; }

@keyframes floatDigit {
    0% { transform: translateY(0); }
    100% { transform: translateY(-7px); }
}

/* 3D Cyber Gyroscope Portal (Light Theme) */
.cyber-gyro-portal {
    position: relative;
    width: 96px;
    height: 96px;
    display: flex;
    align-items: center;
    justify-content: center;
    perspective: 800px;
    transform-style: preserve-3d;
    margin: 0 6px;
}

.gyro-ring {
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.gyro-ring-1 {
    inset: 0;
    border: 2px dashed #0284c7;
    box-shadow: 0 0 16px rgba(2, 132, 199, 0.25);
    animation: spinRing1 9s linear infinite;
}

.gyro-ring-2 {
    inset: 12px;
    border: 2px solid #6366f1;
    border-left-color: transparent;
    border-right-color: transparent;
    box-shadow: 0 0 14px rgba(99, 102, 241, 0.25);
    animation: spinRing2 6s linear infinite reverse;
}

.gyro-ring-3 {
    inset: 24px;
    border: 1.5px dotted #ec4899;
    animation: spinRing3 12s linear infinite;
}

@keyframes spinRing1 {
    0% { transform: rotate3d(1, 1, 0, 0deg); }
    100% { transform: rotate3d(1, 1, 0, 360deg); }
}

@keyframes spinRing2 {
    0% { transform: rotate3d(0, 1, 1, 0deg); }
    100% { transform: rotate3d(0, 1, 1, 360deg); }
}

@keyframes spinRing3 {
    0% { transform: rotate3d(1, 0, 1, 0deg); }
    100% { transform: rotate3d(1, 0, 1, 360deg); }
}

.gyro-core {
    position: relative;
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: linear-gradient(135deg, #4338ca 0%, #0284c7 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 16px;
    box-shadow:
        0 4px 16px rgba(67, 56, 202, 0.4),
        inset 0 0 8px rgba(255, 255, 255, 0.4);
    animation: gyroCorePulse 2.5s ease-in-out infinite;
}

@keyframes gyroCorePulse {
    0%, 100% { transform: scale(1) rotate(0deg); }
    50% { transform: scale(1.1) rotate(90deg); box-shadow: 0 6px 22px rgba(2, 132, 199, 0.5); }
}

/* Headings */
.cyber-title {
    font-family: 'Outfit', sans-serif;
    font-size: 26px;
    font-weight: 800;
    color: #0f172a;
    margin: 8px 0 6px;
    letter-spacing: -0.02em;
}

.cyber-desc {
    font-size: 13px;
    line-height: 1.5;
    color: #64748b;
    max-width: 440px;
    margin: 0 auto 24px;
}

/* Action Buttons */
.cyber-actions {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

.cyber-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 22px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    white-space: nowrap;
    border: none;
}

.cyber-btn-primary {
    background: linear-gradient(135deg, #4338ca 0%, #0284c7 100%);
    color: #ffffff !important;
    box-shadow: 0 4px 16px rgba(67, 56, 202, 0.3);
}

.cyber-btn-primary:hover {
    background: linear-gradient(135deg, #3730a3 0%, #0369a1 100%);
    color: #ffffff !important;
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(67, 56, 202, 0.4);
}

.cyber-btn-primary:hover .cyber-btn-arrow {
    transform: translateX(4px);
}

.cyber-btn-arrow {
    font-size: 11px;
    transition: transform 0.2s ease;
}

.cyber-btn-secondary {
    background: #ffffff;
    color: #334155;
    border: 1px solid #cbd5e1;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}

.cyber-btn-secondary:hover {
    background: #f8fafc;
    color: #0f172a;
    border-color: #94a3b8;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

/* Quick Access Module Dock - Light Frosted Glass */
.cyber-dock {
    width: 100%;
    max-width: 580px;
    background: rgba(255, 255, 255, 0.88);
    border: 1px solid rgba(203, 213, 225, 0.8);
    backdrop-filter: blur(14px);
    border-radius: 16px;
    padding: 12px 14px;
    box-shadow: 0 10px 30px rgba(67, 56, 202, 0.06);
}

.cyber-dock-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
    margin-bottom: 8px;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 6px;
}

.cyber-dock-label i {
    color: #0284c7;
}

.cyber-dock-links {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
}

.cyber-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 10px;
    border-radius: 9px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #334155;
    font-size: 11px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.16s ease;
    white-space: nowrap;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.cyber-chip i {
    color: #6366f1;
    font-size: 11px;
    transition: transform 0.16s ease;
}

.cyber-chip:hover {
    background: #eef2ff;
    border-color: #c7d2fe;
    color: #4338ca;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(67, 56, 202, 0.12);
}

.cyber-chip:hover i {
    transform: scale(1.15);
    color: #0284c7;
}

/* Tight Viewport Tuning to Ensure ZERO Scrolling */
@media (max-height: 700px) {
    .cyber-digit { font-size: 84px; }
    .cyber-gyro-portal { width: 76px; height: 76px; }
    .cyber-title { font-size: 22px; margin: 4px 0; }
    .cyber-desc { font-size: 12px; margin-bottom: 16px; }
    .cyber-badge { margin-bottom: 12px; padding: 4px 12px; }
    .cyber-btn { padding: 9px 18px; font-size: 12px; }
    .cyber-actions { margin-bottom: 16px; }
    .cyber-dock { padding: 8px 10px; }
}

@media (max-width: 600px) {
    .cyber-dock-links {
        grid-template-columns: repeat(2, 1fr);
    }
    .cyber-digit { font-size: 80px; }
    .cyber-gyro-portal { width: 72px; height: 72px; }
}
</style>
