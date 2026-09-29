<template>
  <div class="home">
    <!-- ================= En-tête (style « Console Home ») ================= -->
    <header class="home-head">
      <div>
        <h1>Tableau de bord <button class="info-link" @click="showInfo = !showInfo">Info</button></h1>
        <p>Activité de la plateforme FlashPay — du {{ fmtDate(d?.period.from) }} au {{ fmtDate(d?.period.to) }}</p>
      </div>
      <div class="actions">
        <button class="btn-normal" @click="resetLayout" title="Rétablir les widgets et leur ordre d'origine">Réinitialiser la disposition</button>
        <button class="btn-normal" @click="drawer = true">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v12M2 8h12"/></svg>
          Ajouter des widgets
        </button>
        <router-link class="btn accent" to="/peex">Tester un paiement</router-link>
      </div>
    </header>

    <div v-if="showInfo" class="flash info">
      <div>
        <strong>Personnalisez votre tableau de bord.</strong>
        Glissez un widget par sa poignée <b>⋮⋮</b> pour le déplacer, utilisez le menu <b>⋮</b> pour changer sa taille ou le retirer,
        et « Ajouter des widgets » pour en réafficher. La disposition est enregistrée dans ce navigateur.
      </div>
      <button class="x" @click="showInfo = false" aria-label="Fermer">✕</button>
    </div>

    <!-- ================= Barre de période ================= -->
    <div class="toolbar-line">
      <div class="seg" role="group" aria-label="Période">
        <button v-for="p in PERIODS" :key="p.v" :class="{ on: days === p.v }" @click="setPeriod(p.v)">{{ p.l }}</button>
      </div>
      <label v-if="days === 'custom'" class="date-range">
        <span>Du</span>
        <input v-model="from" type="date" :max="to || today" @change="load" aria-label="Date de début" />
        <span>au</span>
        <input v-model="to" type="date" :min="from" :max="today" @change="load" aria-label="Date de fin" />
      </label>
      <span class="grow"></span>
      <label class="toggle" title="Recharger automatiquement toutes les 60 secondes">
        <input type="checkbox" v-model="autoRefresh" /> <span>Actualisation auto</span>
      </label>
      <span class="updated">
        <StatusIcon :kind="error ? 'err' : d ? 'ok' : 'pending'" />
        {{ error ? 'Erreur de chargement' : d ? 'Mis à jour à ' + updatedAt : 'Chargement…' }}
      </span>
      <button class="icon-btn" @click="load" :disabled="loading" title="Actualiser" aria-label="Actualiser">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" :class="{ rot: loading }"><path d="M14 8a6 6 0 1 1-2-4.5M14 2v4h-4"/></svg>
      </button>
    </div>

    <div v-if="error" class="flash err"><div><strong>Impossible de charger le tableau de bord.</strong><br />{{ error }}</div></div>

    <!-- ================= Grille de widgets ================= -->
    <div class="board" @dragover.prevent>
      <section
        v-for="w in visible" :key="w.key"
        class="widget" :class="['span-' + w.size, { dragging: dragKey === w.key, over: overKey === w.key && dragKey !== w.key }]"
        :draggable="armed === w.key"
        @dragstart="onDragStart(w.key, $event)" @dragenter.prevent="onDragEnter(w.key)" @dragend="onDragEnd"
      >
        <header class="w-head">
          <span class="grip" title="Glisser pour déplacer" @mousedown="armed = w.key" @mouseup="armed = null" @touchstart.passive="armed = w.key">
            <svg width="12" height="16" viewBox="0 0 12 16" fill="currentColor"><circle cx="3" cy="3" r="1.4"/><circle cx="9" cy="3" r="1.4"/><circle cx="3" cy="8" r="1.4"/><circle cx="9" cy="8" r="1.4"/><circle cx="3" cy="13" r="1.4"/><circle cx="9" cy="13" r="1.4"/></svg>
          </span>
          <div class="w-title">
            <h2>{{ W[w.key].title }}
              <span v-if="w.key === 'recent'" class="counter">({{ d?.recent.length ?? 0 }})</span>
              <span v-if="w.key === 'peex' && peex?.sandbox" class="status pending">Sandbox</span>
            </h2>
            <p v-if="W[w.key].desc">{{ W[w.key].desc }}</p>
          </div>

          <!-- Actions propres au widget -->
          <div class="w-tools">
            <template v-if="w.key === 'peex'">
              <span class="muted" v-if="peex">Vérifié à {{ peexAt }}</span>
              <button class="icon-btn" @click="loadPeex(true)" :disabled="peexLoading" title="Rafraîchir les soldes">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" :class="{ rot: peexLoading }"><path d="M14 8a6 6 0 1 1-2-4.5M14 2v4h-4"/></svg>
              </button>
            </template>
            <div v-if="w.key === 'activity'" class="seg sm">
              <button v-for="m in CHART_MODES" :key="m.v" :class="{ on: chartMode === m.v }" @click="chartMode = m.v">{{ m.l }}</button>
            </div>
            <template v-if="w.key === 'ops'">
              <label class="toggle sm"><input type="checkbox" v-model="hideEmpty" /> <span>Masquer les canaux inactifs</span></label>
              <button class="btn-link" @click="toggleAll">{{ allOpen ? 'Tout replier' : 'Tout déplier' }}</button>
            </template>

            <div class="menu-wrap">
              <button class="icon-btn" @click.stop="menuFor = menuFor === w.key ? null : w.key" :aria-expanded="menuFor === w.key" aria-label="Paramètres du widget">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="8" cy="3" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="8" cy="13" r="1.5"/></svg>
              </button>
              <div v-if="menuFor === w.key" class="menu" @click.stop>
                <div class="menu-label">Taille</div>
                <button v-for="s in SIZES" :key="s.v" @click="setSize(w.key, s.v)"><span class="check">{{ w.size === s.v ? '✓' : '' }}</span>{{ s.l }}</button>
                <hr />
                <button @click="move(w.key, -1)" :disabled="isFirst(w.key)"><span class="check"></span>Monter</button>
                <button @click="move(w.key, 1)" :disabled="isLast(w.key)"><span class="check"></span>Descendre</button>
                <hr />
                <button class="danger" @click="hide(w.key)"><span class="check"></span>Retirer le widget</button>
              </div>
            </div>
          </div>
        </header>

        <div class="w-body" :class="{ flush: W[w.key].flush }">
          <!-- ---------- Synthèse ---------- -->
          <div v-if="w.key === 'kpi'" class="kpis">
            <router-link v-for="m in kpiCards" :key="m.key" :to="m.to" class="kpi">
              <div class="k">{{ m.label }}</div>
              <div class="v" :class="{ skeleton: !d }">{{ m.value }}<small v-if="m.unit"> {{ m.unit }}</small></div>
              <div class="kpi-foot">
                <span v-if="m.evo" class="evo" :class="m.evoCls">{{ m.evo }}</span>
                <span class="sub">{{ m.sub }}</span>
              </div>
              <svg v-if="m.spark" class="spark" viewBox="0 0 100 28" preserveAspectRatio="none">
                <path :d="m.spark + ' L100,28 L0,28 Z'" class="spark-area" />
                <path :d="m.spark" class="spark-line" />
              </svg>
            </router-link>
          </div>

          <!-- ---------- Statut des transactions (donut) ---------- -->
          <div v-else-if="w.key === 'status'" class="donut-wrap">
            <div class="donut">
              <Doughnut v-if="donutData" :data="donutData" :options="donutOptions" />
              <div class="donut-center"><b>{{ n(k?.count) }}</b><span>transactions</span></div>
            </div>
            <ul class="legend">
              <li v-for="s in STATUS_TILES" :key="s.key">
                <router-link :to="tx(null, s.key)">
                  <i class="sw" :class="'s-' + s.cls"></i><span>{{ s.label }}</span>
                  <b>{{ n(k?.[s.key]) }}</b><em>{{ pct0(k?.[s.key], k?.count) }}</em>
                </router-link>
              </li>
            </ul>
          </div>

          <!-- ---------- État des services (type « AWS Health ») ---------- -->
          <div v-else-if="w.key === 'health'">
            <div class="counters">
              <div><span>Incidents ouverts</span><b :class="{ 't-err': openIssues }">{{ openIssues }}</b></div>
              <div><span>Demandes PEEX en attente</span><b>{{ n(d?.peex.awaiting ?? 0) }}</b></div>
            </div>
            <ul class="svc-list">
              <li v-for="s in services" :key="s.label">
                <StatusIcon :kind="s.kind" /><span class="lbl">{{ s.label }}</span><span class="st-txt" :class="'t-' + s.kind">{{ s.state }}</span>
              </li>
            </ul>
            <p v-if="d?.peex.last_error" class="hint err-hint">Dernière erreur PEEX : {{ d.peex.last_error }}</p>
          </div>

          <!-- ---------- À traiter (type « Trusted Advisor ») ---------- -->
          <div v-else-if="w.key === 'todo'">
            <div class="todo-sum">
              <div v-for="c in todoSummary" :key="c.kind" :class="'t-' + c.kind"><StatusIcon :kind="c.kind" /><b>{{ c.n }}</b><span>{{ c.l }}</span></div>
            </div>
            <ul class="todo-list">
              <li v-for="t in todos" :key="t.label">
                <router-link :to="t.to">
                  <StatusIcon :kind="t.count ? t.kind : 'ok'" />
                  <span class="lbl">{{ t.label }}</span>
                  <b :class="t.count ? 't-' + t.kind : 'muted'">{{ n(t.count) }}</b>
                </router-link>
              </li>
            </ul>
          </div>

          <!-- ---------- Soldes PEEX ---------- -->
          <div v-else-if="w.key === 'peex'">
            <div v-if="peexError" class="flash err"><div>{{ peexError }}</div></div>
            <router-link to="/peex" class="peex-total">
              <span>Total chez PEEX</span>
              <b :class="{ skeleton: !peex }">{{ peex ? xaf(peex.total) : '—' }}</b>
            </router-link>
            <ul class="peex-list">
              <li v-for="s in PEEX_ACCOUNTS" :key="s.key">
                <router-link to="/peex">
                  <div class="row1">
                    <span class="lbl">{{ s.label }}</span>
                    <span class="amt" v-if="acc(s.key)?.ok">{{ acc(s.key).balance === null ? '—' : xaf(acc(s.key).balance) }}</span>
                    <span class="amt t-err" v-else-if="peex">Indisponible</span>
                    <span class="amt skeleton" v-else>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
                  </div>
                  <template v-if="acc(s.key)?.ok">
                    <div v-if="acc(s.key).activated === false" class="row2"><StatusIcon kind="err" /> Service désactivé chez PEEX</div>
                    <template v-else-if="s.payout">
                      <div class="meter" :title="'Disponible / solde'"><span :class="acc(s.key).low ? 'warn' : 'ok'" :style="{ width: availPct(acc(s.key)) + '%' }"></span></div>
                      <div class="row2">
                        Disponible <b>{{ xaf(acc(s.key).available) }}</b> · Engagé {{ xaf(acc(s.key).reserved) }}
                        <span v-if="acc(s.key).low" class="t-warn"> · <StatusIcon kind="warn" /> Solde bas</span>
                      </div>
                    </template>
                    <div v-else class="row2">Fonds encaissés (mobile money → PEEX)</div>
                  </template>
                  <div v-else-if="peex" class="row2" :title="acc(s.key)?.error"><StatusIcon kind="err" /> PEEX injoignable</div>
                </router-link>
              </li>
            </ul>
          </div>

          <!-- ---------- Activité (graphique) ---------- -->
          <div v-else-if="w.key === 'activity'" class="chart-box">
            <Bar v-if="chartData" :data="chartData" :options="chartOptions" />
            <div v-else class="skeleton" style="height: 100%;"></div>
          </div>

          <!-- ---------- Réseau par rôle ---------- -->
          <div v-else-if="w.key === 'network'" class="roles">
            <router-link v-for="r in NET_ROLES" :key="r.key" :to="r.to" class="role">
              <span class="pastille" :class="{ blue: r.blue }">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="r.icon"></svg>
              </span>
              <span class="txt"><span class="l">{{ r.label }}</span><small>{{ n(net?.counts?.[r.key]?.active) }} actif(s)</small></span>
              <b :class="{ skeleton: !net }">{{ n(net?.counts?.[r.key]?.total) }}</b>
            </router-link>
          </div>

          <!-- ---------- Récemment visités ---------- -->
          <div v-else-if="w.key === 'quick'">
            <ul class="links">
              <li v-for="l in quickLinks" :key="l.path">
                <router-link :to="l.path"><span class="dot-ic">{{ l.label.charAt(0) }}</span>{{ l.label }}</router-link>
              </li>
            </ul>
          </div>

          <!-- ---------- Opérations par activité (table déroulante) ---------- -->
          <div v-else-if="w.key === 'ops'" class="scroll-x">
            <table class="ops">
              <thead>
                <tr>
                  <th>Activité / canal</th><th class="num">Nombre</th><th class="num">Volume (XAF)</th><th style="min-width: 130px;">Répartition</th>
                  <th class="num">Réussies</th><th class="num">Échouées</th><th class="num">Rejetées</th><th class="num">En attente</th>
                  <th class="num">Taux</th><th class="num">Panier moyen</th><th class="num">Évol. volume</th>
                </tr>
              </thead>
              <tbody v-for="f in families" :key="f.key">
                <tr class="fam-row" :class="'fam-' + f.key">
                  <td>
                    <button class="expander" @click="toggle(f.key)" :aria-expanded="!!open[f.key]">
                      <svg width="10" height="10" viewBox="0 0 10 10" :class="{ opened: open[f.key] }"><path d="M3 1l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                    </button>
                    <i class="fam-bar"></i><router-link :to="tx(f.key)">{{ f.label }}</router-link>
                    <small class="muted"> ({{ f.channels.length }})</small>
                  </td>
                  <td class="num"><router-link :to="tx(f.key)">{{ n(f.count) }}</router-link></td>
                  <td class="num">{{ n(f.volume) }}</td>
                  <td><div class="stack" :title="stackTitle(f)"><span v-for="s in STATUS_TILES" :key="s.key" :class="'s-' + s.cls" :style="{ width: (f.count ? f[s.key] * 100 / f.count : 0) + '%' }"></span></div></td>
                  <td class="num c-ok"><router-link :to="tx(f.key, 'successful')">{{ n(f.successful) }}</router-link></td>
                  <td class="num c-err"><router-link :to="tx(f.key, 'failed')">{{ n(f.failed) }}</router-link></td>
                  <td class="num c-warn"><router-link :to="tx(f.key, 'reversed')">{{ n(f.reversed) }}</router-link></td>
                  <td class="num c-pend"><router-link :to="tx(f.key, 'processing')">{{ n(f.processing) }}</router-link></td>
                  <td class="num"><b :class="'t-' + rateClass(rateNum(f))">{{ rate(f) }}</b></td>
                  <td class="num">{{ famPerf(f.key)?.avg_ticket == null ? '—' : short(famPerf(f.key).avg_ticket) }}</td>
                  <td class="num"><span class="evo" :class="evoClass(famPerf(f.key)?.volume, famPerf(f.key)?.prev_volume)">{{ evo(famPerf(f.key)?.volume, famPerf(f.key)?.prev_volume) }}</span></td>
                </tr>
                <template v-if="open[f.key]">
                  <tr v-for="c in f.channels.filter((c) => !hideEmpty || c.count)" :key="c.key" class="ch-row" :class="{ zero: !c.count }">
                    <td><router-link :to="tx(c.key)">{{ c.label }}</router-link></td>
                    <td class="num"><router-link :to="tx(c.key)">{{ n(c.count) }}</router-link></td>
                    <td class="num">{{ n(c.volume) }}</td>
                    <td><div v-if="c.count" class="stack thin"><span v-for="s in STATUS_TILES" :key="s.key" :class="'s-' + s.cls" :style="{ width: (c[s.key] * 100 / c.count) + '%' }"></span></div></td>
                    <td class="num c-ok"><router-link :to="tx(c.key, 'successful')">{{ n(c.successful) }}</router-link></td>
                    <td class="num c-err"><router-link :to="tx(c.key, 'failed')">{{ n(c.failed) }}</router-link></td>
                    <td class="num c-warn"><router-link :to="tx(c.key, 'reversed')">{{ n(c.reversed) }}</router-link></td>
                    <td class="num c-pend"><router-link :to="tx(c.key, 'processing')">{{ n(c.processing) }}</router-link></td>
                    <td class="num">{{ rate(c) }}</td><td></td><td></td>
                  </tr>
                  <tr v-if="hideEmpty && !f.channels.some((c) => c.count)" class="ch-row zero"><td colspan="11">Aucune opération sur ce canal pendant la période.</td></tr>
                </template>
              </tbody>
              <tfoot v-if="d">
                <tr class="fam-row">
                  <td>Total</td><td class="num">{{ n(k.count) }}</td><td class="num">{{ n(k.volume) }}</td>
                  <td><div class="stack" :title="stackTitle(k)"><span v-for="s in STATUS_TILES" :key="s.key" :class="'s-' + s.cls" :style="{ width: (k.count ? k[s.key] * 100 / k.count : 0) + '%' }"></span></div></td>
                  <td class="num c-ok">{{ n(k.successful) }}</td><td class="num c-err">{{ n(k.failed) }}</td>
                  <td class="num c-warn">{{ n(k.reversed) }}</td><td class="num c-pend">{{ n(k.processing) }}</td><td class="num">{{ rate(k) }}</td>
                  <td class="num">{{ perf?.current.avg_ticket == null ? '—' : short(perf.current.avg_ticket) }}</td>
                  <td class="num"><span class="evo" :class="evoClass(perf?.current.volume, perf?.prev.volume)">{{ evo(perf?.current.volume, perf?.prev.volume) }}</span></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <!-- ---------- Période vs précédente ---------- -->
          <table v-else-if="w.key === 'compare'" class="mini">
            <thead><tr><th>Indicateur</th><th class="num">Période</th><th class="num">Précédente</th><th class="num">Évol.</th></tr></thead>
            <tbody>
              <tr v-for="r in KPI_ROWS" :key="r.key">
                <td>{{ r.label }}</td>
                <td class="num"><b>{{ r.fmt(perf?.current[r.key]) }}</b></td>
                <td class="num muted">{{ r.fmt(perf?.prev[r.key]) }}</td>
                <td class="num"><span class="evo" :class="evoClass(perf?.current[r.key], perf?.prev[r.key], r.lowerIsBetter)">{{ evo(perf?.current[r.key], perf?.prev[r.key], r.points) }}</span></td>
              </tr>
            </tbody>
          </table>

          <!-- ---------- Top marchands ---------- -->
          <table v-else-if="w.key === 'merchants'" class="mini">
            <thead><tr><th>#</th><th>Marchand</th><th class="num">Paiements</th><th class="num">Volume</th><th class="num">Réussite</th></tr></thead>
            <tbody>
              <tr v-for="(m, i) in perf?.top_merchants || []" :key="m.id">
                <td class="rank">{{ i + 1 }}</td><td>{{ m.name }}</td><td class="num">{{ n(m.count) }}</td><td class="num"><b>{{ short(m.volume) }}</b></td>
                <td class="num"><span :class="'t-' + rateClass(m.success_rate)">{{ pctTxt(m.success_rate) }}</span></td>
              </tr>
              <tr v-if="perf && !perf.top_merchants.length"><td colspan="5"><div class="empty-sm"><strong>Aucun paiement marchand</strong>sur la période sélectionnée.</div></td></tr>
            </tbody>
          </table>

          <!-- ---------- Top agents ---------- -->
          <table v-else-if="w.key === 'agents'" class="mini">
            <thead><tr><th>#</th><th>Agent</th><th class="num">Dépôts</th><th class="num" title="Retraits au comptoir (cash-out wallet)">Retraits wallet</th><th class="num" title="Retraits par QR code / bon de retrait">Retraits QR</th><th class="num">Volume</th></tr></thead>
            <tbody>
              <tr v-for="(a, i) in perf?.top_agents || []" :key="a.id">
                <td class="rank">{{ i + 1 }}</td><td>{{ a.name }}<div v-if="a.zone" class="hint">{{ a.zone }}</div></td>
                <td class="num">{{ n(a.deposits) }}</td><td class="num">{{ n(a.withdrawals) }}</td><td class="num">{{ n(a.qr_withdrawals) }}</td><td class="num"><b>{{ short(a.volume) }}</b></td>
              </tr>
              <tr v-if="perf && !perf.top_agents.length"><td colspan="6"><div class="empty-sm"><strong>Aucune opération agent</strong>sur la période sélectionnée.</div></td></tr>
            </tbody>
          </table>

          <!-- ---------- Transactions récentes ---------- -->
          <div v-else-if="w.key === 'recent'" class="scroll-x">
            <table>
              <thead><tr><th>Référence</th><th>Type</th><th>Expéditeur</th><th>Bénéficiaire</th><th class="num">Montant</th><th>Statut</th><th>Date</th></tr></thead>
              <tbody>
                <tr v-for="t in d?.recent || []" :key="t.id" style="cursor: pointer;" @click="$router.push('/transactions/' + t.id)">
                  <td><router-link :to="'/transactions/' + t.id" class="mono" @click.stop>{{ t.reference }}</router-link></td>
                  <td>{{ label(t.type) }}</td>
                  <td><strong>{{ t.sender?.name || '—' }}</strong><br /><small class="mono muted">{{ t.sender?.account || label(t.source_rail) }}</small></td>
                  <td><strong>{{ t.beneficiary?.name || '—' }}</strong><br /><small class="mono muted">{{ t.beneficiary?.account || label(t.destination_rail) }}</small></td>
                  <td class="num">{{ xaf(t.amount) }}</td>
                  <td><span class="st-inline" :class="'t-' + statusKind(t.status)"><StatusIcon :kind="statusKind(t.status)" /> {{ label(t.status) }}</span></td>
                  <td>{{ fmtDateTime(t.created_at) }}</td>
                </tr>
                <tr v-if="d && !d.recent.length"><td colspan="7"><div class="empty-sm"><strong>Aucune transaction pour l'instant</strong>Lancez un test depuis la console PEEX.</div></td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <footer v-if="W[w.key].foot" class="w-foot">
          <router-link :to="W[w.key].foot.to">{{ W[w.key].foot.l }}</router-link>
        </footer>
      </section>

      <!-- Emplacement vide quand tout a été retiré -->
      <div v-if="!visible.length" class="widget span-4 empty-board">
        <strong>Aucun widget affiché</strong>
        <p>Ajoutez des widgets pour composer votre tableau de bord.</p>
        <button class="btn-normal" @click="drawer = true">Ajouter des widgets</button>
      </div>
    </div>

    <!-- ================= Panneau « Ajouter des widgets » ================= -->
    <transition name="fade">
      <div v-if="drawer" class="drawer-mask" @click="drawer = false"></div>
    </transition>
    <transition name="slide">
      <aside v-if="drawer" class="drawer" aria-label="Ajouter des widgets">
        <header>
          <h2>Ajouter des widgets</h2>
          <button class="icon-btn" @click="drawer = false" aria-label="Fermer">✕</button>
        </header>
        <p class="muted">Cochez les widgets à afficher. Ils sont ajoutés à la fin du tableau de bord.</p>
        <ul>
          <li v-for="it in layout" :key="it.key">
            <label>
              <input type="checkbox" :checked="!it.hidden" @change="it.hidden = !$event.target.checked; save()" />
              <span><b>{{ W[it.key].title }}</b><small>{{ W[it.key].about }}</small></span>
            </label>
          </li>
        </ul>
        <footer><button class="btn-normal" @click="resetLayout">Réinitialiser</button><button class="btn" @click="drawer = false">Terminé</button></footer>
      </aside>
    </transition>
  </div>
</template>

<script setup>
import { computed, h, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue'
import api from '../services/api'
import { Bar, Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS, Tooltip, Legend, BarElement, BarController, LineElement, LineController,
  PointElement, CategoryScale, LinearScale, ArcElement, DoughnutController, Filler,
} from 'chart.js'

ChartJS.register(Tooltip, Legend, BarElement, BarController, LineElement, LineController, PointElement, CategoryScale, LinearScale, ArcElement, DoughnutController, Filler)

/* ------------------------------------------------------------------ Icônes de statut (façon Cloudscape) */
const StatusIcon = (props) => {
  const k = props.kind
  const paths = {
    ok: [h('circle', { cx: 8, cy: 8, r: 7 }), h('path', { d: 'M4.5 8.2l2.3 2.3 4.7-4.7' })],
    err: [h('circle', { cx: 8, cy: 8, r: 7 }), h('path', { d: 'M5.5 5.5l5 5M10.5 5.5l-5 5' })],
    warn: [h('path', { d: 'M8 1.5L15 14H1z' }), h('path', { d: 'M8 6v3.5M8 11.5v.5' })],
    pending: [h('circle', { cx: 8, cy: 8, r: 7 }), h('path', { d: 'M8 4v4l2.5 2' })],
    muted: [h('circle', { cx: 8, cy: 8, r: 7 }), h('path', { d: 'M5 8h6' })],
    info: [h('circle', { cx: 8, cy: 8, r: 7 }), h('path', { d: 'M8 7v4.5M8 4.5v.5' })],
  }
  return h('svg', { class: ['sicon', 'i-' + k], width: 14, height: 14, viewBox: '0 0 16 16', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' }, paths[k] || paths.muted)
}
StatusIcon.props = ['kind']

/* ------------------------------------------------------------------ Catalogue des widgets */
const W = {
  kpi: { title: 'Synthèse de la période', about: 'Indicateurs clés, évolution et tendance.', size: 4 },
  status: { title: 'Statut des transactions', about: 'Répartition réussies / échouées / rejetées / en attente.', size: 1, foot: { to: '/transactions', l: 'Voir les transactions' } },
  health: { title: 'État des services', about: 'Disponibilité de l’API, de PEEX et des canaux.', size: 1, foot: { to: '/peex', l: 'Ouvrir la console PEEX' } },
  todo: { title: 'À traiter', about: 'Validations, échecs et alertes nécessitant une action.', size: 1, foot: { to: '/notifications', l: 'Voir les notifications' } },
  quick: { title: 'Récemment visités', about: 'Vos derniers écrans et les accès rapides.', size: 1 },
  peex: { title: 'Soldes PEEX', desc: 'Disponible = solde − montants engagés', about: 'Soldes des comptes FlashPay chez PEEX.', size: 2, foot: { to: '/peex', l: 'Passerelle PEEX' } },
  activity: { title: 'Activité', desc: 'Volume réussi et nombre de transactions par jour', about: 'Graphique quotidien du volume et du nombre.', size: 2 },
  network: { title: 'Réseau par rôle', about: 'Comptes des six profils de l’application mobile.', size: 2, foot: { to: '/roles', l: 'Rôles & habilitations' } },
  compare: { title: 'Période vs précédente', about: 'Comparaison des indicateurs avec la période précédente.', size: 2, flush: true },
  ops: { title: 'Opérations par activité', desc: 'Dépliez une activité pour voir ses canaux — cliquez un chiffre pour ouvrir les transactions', about: 'Tableau détaillé par famille et canal.', size: 4, flush: true },
  merchants: { title: 'Top 5 marchands', about: 'Marchands les plus actifs sur la période.', size: 2, flush: true, foot: { to: '/merchants', l: 'Tous les marchands' } },
  agents: { title: 'Top 5 agents', about: 'Agents les plus actifs sur la période.', size: 2, flush: true, foot: { to: '/agents', l: 'Tous les agents' } },
  recent: { title: 'Transactions récentes', about: 'Les dernières opérations de la plateforme.', size: 4, flush: true, foot: { to: '/transactions', l: 'Voir toutes les transactions' } },
}
const DEFAULT_ORDER = ['kpi', 'status', 'health', 'todo', 'quick', 'peex', 'activity', 'ops', 'network', 'compare', 'merchants', 'agents', 'recent']
const LS_KEY = 'fp_admin_dashboard_layout_v2'
const SIZES = [{ v: 1, l: 'Petite (1/4)' }, { v: 2, l: 'Moyenne (1/2)' }, { v: 3, l: 'Grande (3/4)' }, { v: 4, l: 'Pleine largeur' }]

const defaultLayout = () => DEFAULT_ORDER.map((key) => ({ key, size: W[key].size, hidden: false }))
function readLayout() {
  try {
    const saved = JSON.parse(localStorage.getItem(LS_KEY) || 'null')
    if (Array.isArray(saved)) {
      const known = saved.filter((it) => W[it.key])
      // Nouveaux widgets ajoutés depuis la dernière sauvegarde
      DEFAULT_ORDER.forEach((key) => { if (!known.some((it) => it.key === key)) known.push({ key, size: W[key].size, hidden: false }) })
      return known
    }
  } catch (_) {}
  return defaultLayout()
}
const layout = reactive(readLayout())
const visible = computed(() => layout.filter((it) => !it.hidden))
const save = () => { try { localStorage.setItem(LS_KEY, JSON.stringify(layout)) } catch (_) {} }
function resetLayout() { layout.splice(0, layout.length, ...defaultLayout()); save(); menuFor.value = null }
const idx = (key) => layout.findIndex((it) => it.key === key)
const visIdx = (key) => visible.value.findIndex((it) => it.key === key)
const isFirst = (key) => visIdx(key) === 0
const isLast = (key) => visIdx(key) === visible.value.length - 1
function setSize(key, s) { layout[idx(key)].size = s; save(); menuFor.value = null }
function hide(key) { layout[idx(key)].hidden = true; save(); menuFor.value = null }
function move(key, dir) {
  const v = visible.value
  const target = v[visIdx(key) + dir]
  if (!target) return
  const a = idx(key); const b = idx(target.key)
  const [it] = layout.splice(a, 1)
  layout.splice(b, 0, it)
  save(); menuFor.value = null
}

/* Glisser-déposer (poignée ⋮⋮) */
const armed = ref(null)
const dragKey = ref(null)
const overKey = ref(null)
function onDragStart(key, e) { dragKey.value = key; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', key) } catch (_) {} }
function onDragEnter(key) {
  if (!dragKey.value || key === dragKey.value) return
  overKey.value = key
  const a = idx(dragKey.value); const b = idx(key)
  const [it] = layout.splice(a, 1)
  layout.splice(b, 0, it)
}
function onDragEnd() { dragKey.value = null; overKey.value = null; armed.value = null; save() }

/* Menus */
const menuFor = ref(null)
const closeMenus = () => { menuFor.value = null }
const drawer = ref(false)
const showInfo = ref(false)

/* ------------------------------------------------------------------ Période */
const PERIODS = [{ v: 1, l: "Aujourd'hui" }, { v: 7, l: '7 j' }, { v: 14, l: '14 j' }, { v: 30, l: '30 j' }, { v: 90, l: '90 j' }, { v: 'custom', l: 'Personnalisée' }]
const d = ref(null)
const days = ref(14)
const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const from = ref('')
const to = ref('')
const periodQuery = () => (days.value === 'custom'
  ? { ...(from.value ? { from: from.value } : {}), ...(to.value ? { to: to.value } : {}) }
  : { days: days.value })
function setPeriod(v) {
  days.value = v
  if (v === 'custom') {
    if (!from.value) from.value = d.value?.period.from?.slice(0, 10) || today
    if (!to.value) to.value = today
  }
  load()
}
const loading = ref(false)
const error = ref('')
const updatedAt = ref('')
const autoRefresh = ref(true)
try { autoRefresh.value = localStorage.getItem('fp_admin_dash_auto') !== 'off' } catch (_) {}
watch(autoRefresh, (v) => { try { localStorage.setItem('fp_admin_dash_auto', v ? 'on' : 'off') } catch (_) {} })

const families = computed(() => d.value?.families || [])
const k = computed(() => d.value?.kpi)
const perf = computed(() => d.value?.performance)
const famPerf = (key) => perf.value?.by_family?.find((r) => r.key === key)

/* ------------------------------------------------------------------ Formats */
const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => (v == null ? '—' : nf.format(v))
const xaf = (v) => (v == null ? '—' : nf.format(v) + ' XAF')
const short = (v) => {
  if (v == null) return '—'
  if (Math.abs(v) >= 1e9) return (v / 1e9).toFixed(1).replace('.', ',') + ' Md'
  if (Math.abs(v) >= 1e6) return (v / 1e6).toFixed(1).replace('.', ',') + ' M'
  if (Math.abs(v) >= 1e4) return (v / 1e3).toFixed(0) + ' k'
  return nf.format(v)
}
const pctTxt = (v) => (v == null ? '—' : String(v).replace('.', ',') + ' %')
const pct0 = (a, b) => (b ? Math.round((a * 100) / b) + ' %' : '—')
const dur = (s) => (s == null ? '—' : s < 60 ? s + ' s' : s < 3600 ? Math.round(s / 60) + ' min' : (s / 3600).toFixed(1).replace('.', ',') + ' h')
const fmtDate = (s) => (s ? new Date(s).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) : '…')
const fmtDateTime = (s) => new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })

const evo = (c, p, points = false) => {
  if (c == null || p == null) return '—'
  if (points) { const dlt = Math.round((c - p) * 10) / 10; return (dlt > 0 ? '▲ +' : dlt < 0 ? '▼ ' : '= ') + String(dlt).replace('.', ',') + ' pt' }
  if (!p) return c ? '▲ nouveau' : '='
  const r = Math.round(((c - p) * 100) / p)
  return (r > 0 ? '▲ +' : r < 0 ? '▼ ' : '= ') + r + ' %'
}
const evoClass = (c, p, lowerIsBetter = false) => {
  if (c == null || p == null || c === p) return 'flat'
  return (c > p) !== lowerIsBetter ? 'up' : 'down'
}
const rateClass = (v) => (v == null ? 'muted' : v >= 90 ? 'ok' : v >= 70 ? 'warn' : 'err')

const STATUS_TILES = [
  { key: 'successful', label: 'Réussies', cls: 'ok' },
  { key: 'failed', label: 'Échouées', cls: 'err' },
  { key: 'reversed', label: 'Rejetées', cls: 'warn' },
  { key: 'processing', label: 'En attente', cls: 'pend' },
]
const tx = (channel, status) => ({ path: '/transactions', query: { ...periodQuery(), ...(channel ? { channel } : {}), ...(status ? { status } : {}) } })
const rateNum = (r) => { const done = r.successful + r.failed + r.reversed; return done ? Math.round((r.successful * 100) / done) : null }
const rate = (r) => { const v = rateNum(r); return v == null ? '—' : v + ' %' }
const stackTitle = (f) => STATUS_TILES.map((s) => `${s.label} : ${f[s.key]}`).join(' · ')

const LABELS = {
  p2p: 'Transfert P2P', merchant_payment: 'Paiement marchand', cash_in: 'Cash-in', cash_out: 'Cash-out',
  collection: 'Encaissement USSD', withdrawal: 'Retrait mobile money', cash_pickup: 'Retrait cash (agent)', atm_withdrawal: 'Retrait GAB', atm: 'GAB', cash: 'Cash', qr_payment: 'Paiement QR', nfc_payment: 'Paiement NFC', manual_payment: 'Paiement manuel',
  peex: 'PEEX', wallet: 'Wallet',
  successful: 'Réussie', processing: 'En attente', failed: 'Échouée', reversed: 'Rejetée',
}
const label = (key) => LABELS[key] || key
const statusKind = (s) => ({ successful: 'ok', processing: 'pending', failed: 'err', reversed: 'warn' }[s] || 'muted')

/* ------------------------------------------------------------------ Synthèse (KPI + sparklines) */
const KPI_ROWS = [
  { key: 'transactions', label: 'Transactions', fmt: (v) => n(v) },
  { key: 'volume', label: 'Volume réussi (XAF)', fmt: (v) => short(v) },
  { key: 'success_rate', label: 'Taux de réussite', fmt: (v) => pctTxt(v), points: true },
  { key: 'avg_ticket', label: 'Panier moyen (XAF)', fmt: (v) => (v == null ? '—' : n(v)) },
  { key: 'fees', label: 'Frais perçus (XAF)', fmt: (v) => short(v) },
  { key: 'active_users', label: 'Utilisateurs actifs', fmt: (v) => n(v) },
  { key: 'new_accounts', label: 'Nouveaux comptes', fmt: (v) => n(v) },
  { key: 'avg_delay', label: 'Délai moyen de traitement', fmt: dur, lowerIsBetter: true },
]
function sparkPath(vals) {
  if (!vals || vals.length < 2 || !vals.some((v) => v)) return null
  const max = Math.max(...vals) || 1
  const step = 100 / (vals.length - 1)
  return vals.map((v, i) => `${i ? 'L' : 'M'}${(i * step).toFixed(1)},${(26 - (v / max) * 22).toFixed(1)}`).join(' ')
}
const kpiCards = computed(() => {
  const c = perf.value?.current || {}; const p = perf.value?.prev || {}
  const s = d.value?.series || []
  const card = (key, label, value, unit, sub, to, cur, prev, opts = {}) => ({
    key, label, value, unit, sub, to,
    evo: perf.value ? evo(cur, prev, opts.points) : '',
    evoCls: evoClass(cur, prev, opts.lower),
    spark: opts.spark ? sparkPath(opts.spark) : null,
  })
  return [
    card('count', 'Transactions', n(k.value?.count), '', `Aujourd'hui : ${n(k.value?.today_transactions)}`, tx(), c.transactions, p.transactions, { spark: s.map((r) => r.count) }),
    card('vol', 'Volume réussi', short(k.value?.volume_successful), 'XAF', `Volume total : ${xaf(k.value?.volume)}`, tx(null, 'successful'), c.volume, p.volume, { spark: s.map((r) => r.volume) }),
    card('rate', 'Taux de réussite', k.value?.success_rate == null ? '—' : String(k.value.success_rate).replace('.', ',') + ' %', '', `${n(k.value?.failed)} échouée(s)`, tx(null, 'failed'), c.success_rate, p.success_rate, { points: true }),
    card('fees', 'Frais perçus', short(k.value?.fees), 'XAF', 'Commissions de la période', '/tariffs', c.fees, p.fees),
    card('users', 'Utilisateurs actifs', n(c.active_users), '', `${n(c.new_accounts)} nouveau(x) compte(s)`, '/accounts', c.active_users, p.active_users),
    card('wallets', 'Solde des wallets', short(k.value?.wallets_balance), 'XAF', `${n(d.value?.users.active)} compte(s) actif(s)`, '/accounts', null, null),
  ]
})

/* ------------------------------------------------------------------ Donut statuts */
const STATUS_COLORS = { ok: '#037f0c', err: '#d91515', warn: '#d97706', pend: '#0972d3' }
const donutData = computed(() => {
  if (!k.value) return null
  const vals = STATUS_TILES.map((s) => k.value[s.key] || 0)
  const empty = !vals.some((v) => v)
  return {
    labels: STATUS_TILES.map((s) => s.label),
    datasets: [{ data: empty ? [1] : vals, backgroundColor: empty ? ['#e9ebed'] : STATUS_TILES.map((s) => STATUS_COLORS[s.cls]), borderWidth: 2, borderColor: '#fff', hoverOffset: 4 }],
  }
})
const donutOptions = { responsive: true, maintainAspectRatio: false, cutout: '72%', plugins: { legend: { display: false }, tooltip: { filter: () => !!k.value?.count } } }

/* ------------------------------------------------------------------ Graphique activité */
const CHART_MODES = [{ v: 'both', l: 'Les deux' }, { v: 'volume', l: 'Volume' }, { v: 'count', l: 'Nombre' }]
const chartMode = ref('both')
const chartData = computed(() => {
  if (!d.value) return null
  const s = d.value.series
  const ds = []
  if (chartMode.value !== 'count') ds.push({ type: 'bar', label: 'Volume réussi (XAF)', data: s.map((r) => r.volume), backgroundColor: '#1e3a8a', borderRadius: 4, maxBarThickness: 28, yAxisID: 'y', order: 2 })
  if (chartMode.value !== 'volume') ds.push({ type: 'line', label: 'Transactions', data: s.map((r) => r.count), borderColor: '#e11d2a', backgroundColor: 'rgba(225,29,42,.08)', fill: chartMode.value === 'count', pointRadius: 2.5, tension: .3, cubicInterpolationMode: 'monotone', yAxisID: chartMode.value === 'count' ? 'y' : 'y1', order: 1 })
  return { labels: s.map((r) => new Date(r.date).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })), datasets: ds }
})
const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: { size: 12 } } },
    tooltip: { callbacks: { label: (c) => `${c.dataset.label} : ${nf.format(c.parsed.y)}` } },
  },
  scales: {
    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
    y: { beginAtZero: true, grid: { color: '#eef0f4' }, ticks: { callback: (v) => short(v), precision: 0, font: { size: 11 } } },
    ...(chartMode.value === 'both' ? { y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { precision: 0, font: { size: 11 } } } } : {}),
  },
}))

/* ------------------------------------------------------------------ État des services / À traiter */
const services = computed(() => {
  const px = d.value?.peex
  return [
    { label: 'API FlashPay', kind: error.value ? 'err' : 'ok', state: error.value ? 'Indisponible' : 'Opérationnelle' },
    { label: `PEEX (${px?.sandbox ? 'sandbox' : 'production'})`, kind: px?.last_error ? 'warn' : 'ok', state: px?.last_error ? 'Erreurs récentes' : 'Normal' },
    { label: 'Mobile money (MTN, Airtel…)', kind: 'ok', state: 'Via PEEX' },
    { label: 'Réseau d’agents', kind: 'ok', state: 'Actif' },
    { label: 'Paiement NFC / TPE', kind: 'ok', state: 'Actif' },
    { label: 'Cartes Visa / Mastercard', kind: px?.sandbox ? 'warn' : 'muted', state: px?.sandbox ? 'Simulées' : 'Bientôt' },
    { label: 'GAB / virement bancaire', kind: 'muted', state: 'Bientôt' },
  ]
})
const openIssues = computed(() => services.value.filter((s) => s.kind === 'err' || s.kind === 'warn').length)
const lowPeex = computed(() => PEEX_ACCOUNTS.filter((s) => acc(s.key)?.low || (acc(s.key) && !acc(s.key).ok)).length)
const todos = computed(() => [
  { label: 'Transactions échouées', count: k.value?.failed ?? 0, kind: 'err', to: tx(null, 'failed') },
  { label: 'Soldes PEEX bas / indisponibles', count: lowPeex.value, kind: 'err', to: '/peex' },
  { label: 'Transactions rejetées', count: k.value?.reversed ?? 0, kind: 'warn', to: tx(null, 'reversed') },
  { label: 'Marchands à valider', count: d.value?.pending.merchants ?? 0, kind: 'warn', to: '/merchants' },
  { label: 'Agents à valider', count: d.value?.pending.agents ?? 0, kind: 'warn', to: '/agents' },
  { label: 'Demandes PEEX en attente', count: d.value?.peex.awaiting ?? 0, kind: 'pending', to: '/peex' },
])
const todoSummary = computed(() => {
  const t = todos.value
  return [
    { kind: 'err', l: 'Action requise', n: t.filter((x) => x.kind === 'err' && x.count).length },
    { kind: 'warn', l: 'À examiner', n: t.filter((x) => x.kind !== 'err' && x.count).length },
    { kind: 'ok', l: 'Aucun problème', n: t.filter((x) => !x.count).length },
  ]
})

/* ------------------------------------------------------------------ Récemment visités */
const SERVICES = {
  '/transactions': 'Transactions', '/peex': 'Passerelle PEEX', '/merchants': 'Marchands', '/agents': 'Agents', '/clients': 'Clients',
  '/cashiers': 'Caissiers', '/roles': 'Rôles & habilitations', '/settlements': 'Règlements', '/tariffs': 'Tarifs', '/users': 'Utilisateurs',
  '/accounts': 'Comptes', '/corridors': 'Corridors', '/notifications': 'Notifications', '/kyc': 'KYC', '/float-requests': 'Demandes de float',
  '/support': 'Support', '/fraud': 'Fraude', '/reconciliation': 'Réconciliation', '/audit': 'Journal d’audit', '/ecommerce': 'E-commerce & API',
  '/mini-programs': 'Mini-programmes', '/commissions': 'Commissions', '/settings': 'Paramètres',
}
const quickLinks = computed(() => {
  let recent = []
  try { recent = JSON.parse(localStorage.getItem('fp_admin_recent') || '[]') } catch (_) {}
  const base = (p) => '/' + (p.split('?')[0].split('/')[1] || '')
  const seen = new Set()
  const out = []
  for (const p of [...recent.map(base), '/transactions', '/peex', '/merchants', '/agents', '/kyc', '/reconciliation', '/tariffs']) {
    if (SERVICES[p] && !seen.has(p)) { seen.add(p); out.push({ path: p, label: SERVICES[p] }) }
    if (out.length >= 7) break
  }
  return out
})

/* ------------------------------------------------------------------ Opérations (dépliage) */
const open = reactive({})
const hideEmpty = ref(true)
const toggle = (key) => { open[key] = !open[key] }
const allOpen = computed(() => families.value.length && families.value.every((f) => open[f.key]))
const toggleAll = () => { const v = !allOpen.value; families.value.forEach((f) => { open[f.key] = v }) }

/* ------------------------------------------------------------------ Réseau par rôle */
const net = ref(null)
const NET_ROLES = [
  { key: 'client', label: 'Clients', to: '/clients', blue: true, icon: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>' },
  { key: 'merchant', label: 'Marchands', to: '/merchants', icon: '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/><path d="M5 13v7h14v-7"/>' },
  { key: 'cashier', label: 'Caissiers', to: '/cashiers', blue: true, icon: '<rect x="4" y="3" width="12" height="7" rx="1.5"/><path d="M3 21h18l-2-9H5z"/>' },
  { key: 'agent', label: 'Agents', to: '/agents?level=simple', icon: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>' },
  { key: 'sub_agent', label: 'Sous-agents', to: '/agents?level=sub', blue: true, icon: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M12 11v6M9 14h6"/>' },
  { key: 'super_agent', label: 'Super-agents', to: '/agents?level=super', icon: '<path d="M3 18h18M4 18l-1-10 5 4 4-7 4 7 5-4-1 10"/>' },
]

/* ------------------------------------------------------------------ Soldes PEEX */
const PEEX_ACCOUNTS = [
  { key: 'remittance', label: 'Remittance / distribution', payout: true },
  { key: 'disbursement', label: 'Décaissement', payout: true },
  { key: 'collect', label: 'Collecte', payout: false },
]
const peex = ref(null)
const peexLoading = ref(false)
const peexError = ref('')
const peexAt = ref('')
const acc = (key) => peex.value?.accounts?.[key]
const availPct = (a) => (a?.balance ? Math.max(0, Math.min(100, Math.round(((a.available ?? 0) * 100) / a.balance))) : 0)
function loadPeex(refresh = false) {
  peexLoading.value = true
  peexError.value = ''
  api.get('/admin/peex/balances', { params: refresh ? { refresh: 1 } : {} })
    .then(({ data }) => {
      peex.value = data
      peexAt.value = new Date(data.checked_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
    })
    .catch((e) => { peexError.value = 'Soldes PEEX indisponibles : ' + (e.response?.data?.message || e.message) })
    .finally(() => { peexLoading.value = false })
}
function loadNet() { api.get('/admin/roles').then(({ data }) => { net.value = data }).catch(() => {}) }

async function load() {
  loadNet()
  loadPeex()
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/dashboard', { params: periodQuery() })
    d.value = data
    updatedAt.value = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  } catch (e) {
    error.value = e.response?.status === 403
      ? 'Tableau de bord réservé aux Super Admins.'
      : (e.response?.data?.message || e.message)
  } finally {
    loading.value = false
  }
}

let timer = null
onMounted(() => {
  load()
  timer = setInterval(() => { if (autoRefresh.value && !document.hidden) load() }, 60000)
  document.addEventListener('click', closeMenus)
})
onBeforeUnmount(() => { clearInterval(timer); document.removeEventListener('click', closeMenus) })
</script>

<style scoped>
/* ================= En-tête ================= */
.home-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
.home-head h1 { font-size: 26px; line-height: 34px; font-weight: 750; letter-spacing: -.02em; display: flex; align-items: center; gap: 10px; }
.home-head p { margin: 4px 0 0; color: var(--text-2); }
.info-link { background: none; border: 0; padding: 0; font: inherit; font-size: 13px; font-weight: 700; color: var(--link); cursor: pointer; }
.info-link:hover { text-decoration: underline; }
.flash .x { margin-left: auto; background: none; border: 0; cursor: pointer; color: var(--text-2); font-size: 14px; }

.toolbar-line { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.toolbar-line .grow { flex: 1; }
.updated { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-2); }

/* Contrôle segmenté */
.seg { display: inline-flex; border: 1px solid var(--border-strong); border-radius: 999px; background: #fff; padding: 2px; }
.seg button { border: 0; background: none; font: inherit; font-size: 13px; font-weight: 600; color: var(--text-2); padding: 0 12px; height: 30px; border-radius: 999px; cursor: pointer; white-space: nowrap; }
.seg button:hover { color: var(--text); background: var(--surface-2); }
.seg button.on { background: var(--brand); color: #fff; }
.seg.sm button { height: 24px; padding: 0 9px; font-size: 12px; }

.toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-2); cursor: pointer; user-select: none; }
.toggle input { min-height: 0; width: 15px; height: 15px; accent-color: var(--brand); }
.toggle.sm { font-size: 12px; }
.icon-btn { width: 32px; height: 32px; border-radius: 999px; border: 1px solid transparent; background: none; color: var(--text-2); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
.icon-btn:hover { background: var(--surface-2); color: var(--text); border-color: var(--border); }
.icon-btn:disabled { opacity: .5; cursor: default; }
.date-range { display: inline-flex; align-items: center; gap: 6px; }
.date-range span { color: var(--text-2); font-size: 13px; }
.rot { animation: spin 1s linear infinite; }

/* ================= Grille (board) ================= */
.board { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; grid-auto-flow: row dense; align-items: stretch; }
.span-1 { grid-column: span 1; }
.span-2 { grid-column: span 2; }
.span-3 { grid-column: span 3; }
.span-4 { grid-column: span 4; }
@media (max-width: 1280px) {
  .board { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .span-3, .span-4 { grid-column: span 2; }
}
@media (max-width: 760px) {
  .board { grid-template-columns: 1fr; }
  .span-1, .span-2, .span-3, .span-4 { grid-column: span 1; }
  .toolbar-line .grow { display: none; }
}

.widget {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
  display: flex; flex-direction: column; min-width: 0; position: relative; transition: box-shadow .15s, border-color .15s, opacity .15s;
}
.widget:hover { box-shadow: 0 2px 4px rgba(16, 24, 40, .06), 0 4px 12px rgba(16, 24, 40, .06); }
.widget.dragging { opacity: .45; border-style: dashed; }
.widget.over { border-color: var(--link); }

.w-head { display: flex; align-items: flex-start; gap: 8px; padding: 12px 12px 10px 8px; }
.grip { color: var(--text-3); cursor: grab; padding: 5px 4px; border-radius: 6px; flex: none; opacity: .55; transition: opacity .15s; }
.widget:hover .grip { opacity: 1; }
.grip:hover { background: var(--surface-2); color: var(--text-2); }
.grip:active { cursor: grabbing; }
.w-title { flex: 1; min-width: 0; padding-top: 2px; }
.w-title h2 { font-size: 16px; line-height: 22px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.w-title p { margin: 2px 0 0; color: var(--text-2); font-size: 12.5px; line-height: 17px; }
.counter { color: var(--text-3); font-weight: 500; }
.w-tools { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.w-tools .muted { font-size: 12px; color: var(--text-2); }
.w-body { padding: 4px 20px 18px; flex: 1; min-width: 0; }
.w-body.flush { padding: 0; border-top: 1px solid var(--border); overflow-x: auto; }
@media (max-width: 760px) {
  .w-head { flex-wrap: wrap; }
  .w-title { flex-basis: calc(100% - 70px); }
  .w-tools { width: 100%; justify-content: flex-start; padding-left: 26px; }
  .w-tools .menu-wrap { margin-left: auto; }
  .home-head .actions { width: 100%; }
}
.w-foot { border-top: 1px solid var(--border); padding: 10px 20px; text-align: center; font-size: 13.5px; font-weight: 600; }
.w-foot a:hover { text-decoration: underline; }

/* Menu ⋮ */
.menu-wrap { position: relative; }
.menu { position: absolute; right: 0; top: 36px; z-index: 30; min-width: 200px; background: #fff; border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-lg); padding: 6px; }
.menu-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-3); padding: 6px 10px 2px; }
.menu button { display: flex; width: 100%; align-items: center; gap: 4px; background: none; border: 0; text-align: left; font: inherit; font-size: 13.5px; padding: 7px 10px; border-radius: 8px; cursor: pointer; color: var(--text); }
.menu button:hover:not(:disabled) { background: var(--info-bg); }
.menu button:disabled { color: var(--text-3); cursor: default; }
.menu button.danger { color: var(--err); }
.menu .check { width: 18px; color: var(--link); font-weight: 700; }
.menu hr { border: 0; border-top: 1px solid var(--border); margin: 4px 0; }

/* ================= Icônes / couleurs ================= */
:deep(.sicon) { flex: none; vertical-align: -2px; }
:deep(.i-ok) { color: #037f0c; }
:deep(.i-err) { color: #d91515; }
:deep(.i-warn) { color: #b45309; }
:deep(.i-pending) { color: #0972d3; }
:deep(.i-muted) { color: #8d99a8; }
:deep(.i-info) { color: #0972d3; }
.t-ok { color: #037f0c; } .t-err { color: #d91515; } .t-warn { color: #b45309; } .t-pending { color: #0972d3; } .t-muted, .muted { color: var(--text-2); }
.s-ok { background: #037f0c; } .s-err { background: #d91515; } .s-warn { background: #d97706; } .s-pend { background: #0972d3; }
.c-ok a { color: #037f0c; } .c-err a { color: #d91515; } .c-warn a { color: #b45309; } .c-pend a { color: #0972d3; }

.evo { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 1px 7px; border-radius: 99px; white-space: nowrap; }
.evo.up { color: var(--ok); background: var(--ok-bg); }
.evo.down { color: var(--err); background: var(--err-bg); }
.evo.flat { color: var(--text-2); background: #f1f5f9; }

/* ================= Synthèse ================= */
.kpis { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); }
.kpi { position: relative; display: block; padding: 6px 18px 30px; border-left: 1px solid var(--border); color: inherit; text-decoration: none; border-radius: 8px; overflow: hidden; }
.kpi:first-child { border-left: 0; padding-left: 4px; }
.kpi:hover { background: var(--surface-2); color: inherit; }
.kpi .k { font-size: 13px; color: var(--text-2); font-weight: 600; margin-bottom: 2px; }
.kpi .v { font-size: 28px; line-height: 36px; font-weight: 750; letter-spacing: -.02em; white-space: nowrap; }
.kpi .v.skeleton { max-width: 90px; }
.kpi .v small { margin-left: 4px; font-size: 13px; font-weight: 500; color: var(--text-2); letter-spacing: 0; }
.kpi-foot { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 2px; }
.kpi .sub { font-size: 12px; color: var(--text-2); }
.spark { position: absolute; left: 0; right: 0; bottom: 0; width: 100%; height: 26px; }
.spark-line { fill: none; stroke: var(--link); stroke-width: 1.5; vector-effect: non-scaling-stroke; }
.spark-area { fill: rgba(36, 70, 166, .08); stroke: none; }
@media (max-width: 1280px) { .kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); row-gap: 12px; } .kpi:nth-child(3n + 1) { border-left: 0; } }
@media (max-width: 600px) { .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .kpi:nth-child(odd) { border-left: 0; } .kpi:nth-child(3n + 1):not(:nth-child(odd)) { border-left: 1px solid var(--border); } }

/* ================= Donut ================= */
.donut-wrap { display: flex; flex-direction: column; gap: 12px; }
.donut { position: relative; height: 150px; }
.donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
.donut-center b { font-size: 24px; line-height: 28px; }
.donut-center span { font-size: 11.5px; color: var(--text-2); }
.legend { list-style: none; margin: 0; padding: 0; }
.legend a { display: flex; align-items: center; gap: 8px; padding: 5px 6px; border-radius: 6px; color: inherit; font-size: 13px; }
.legend a:hover { background: var(--surface-2); }
.legend .sw { width: 10px; height: 10px; border-radius: 3px; flex: none; }
.legend span { flex: 1; }
.legend em { font-style: normal; color: var(--text-2); font-size: 12px; min-width: 38px; text-align: right; }

/* ================= État des services / À traiter ================= */
.counters { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
.counters div { display: flex; flex-direction: column; }
.counters span { font-size: 12px; color: var(--text-2); line-height: 16px; }
.counters b { font-size: 26px; line-height: 34px; }
.svc-list, .todo-list, .links, .peex-list { list-style: none; margin: 0; padding: 0; }
.svc-list li { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-top: 1px solid var(--border); font-size: 13px; }
.svc-list .lbl { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.st-txt { font-size: 12px; font-weight: 600; white-space: nowrap; }
.err-hint { margin: 8px 0 0; color: var(--err); }
.todo-sum { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 10px; }
.todo-sum div { display: flex; flex-direction: column; align-items: flex-start; gap: 0; padding: 8px 10px; background: var(--surface-2); border-radius: 10px; }
.todo-sum b { font-size: 22px; line-height: 28px; color: inherit; }
.todo-sum span { font-size: 11px; color: var(--text-2); line-height: 14px; }
.todo-list a { display: flex; align-items: center; gap: 8px; padding: 7px 4px; border-top: 1px solid var(--border); color: inherit; font-size: 13px; }
.todo-list a:hover { background: var(--surface-2); }
.todo-list .lbl { flex: 1; }

/* ================= Récemment visités ================= */
.links a { display: flex; align-items: center; gap: 10px; padding: 7px 6px; border-radius: 8px; font-weight: 600; font-size: 13.5px; }
.links a:hover { background: var(--info-bg); }
.dot-ic { width: 26px; height: 26px; border-radius: 8px; background: var(--soft); color: var(--brand); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; flex: none; }
.links li:nth-child(even) .dot-ic { background: var(--rose); color: var(--accent); }

/* ================= Soldes PEEX ================= */
.peex-total { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; padding: 12px 14px; background: var(--soft); border-radius: 12px; color: var(--brand); margin-bottom: 8px; }
.peex-total span { font-weight: 600; font-size: 13px; }
.peex-total b { font-size: 24px; line-height: 30px; min-width: 80px; text-align: right; }
.peex-list a { display: block; padding: 10px 4px; border-top: 1px solid var(--border); color: inherit; }
.peex-list li:first-child a { border-top: 0; }
.peex-list a:hover { background: var(--surface-2); }
.peex-list .row1 { display: flex; justify-content: space-between; gap: 10px; font-size: 13.5px; }
.peex-list .lbl { font-weight: 600; }
.peex-list .amt { font-weight: 700; white-space: nowrap; }
.peex-list .row2 { font-size: 12px; color: var(--text-2); margin-top: 4px; }
.peex-list .meter { margin-top: 6px; height: 5px; }
.peex-list .meter span.ok { background: #037f0c; } .peex-list .meter span.warn { background: #d97706; }

/* ================= Graphique ================= */
.chart-box { height: 290px; }

/* ================= Réseau ================= */
.roles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.role { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 12px; color: inherit; }
.role:hover { border-color: var(--link); background: var(--surface-2); color: inherit; }
.role .txt { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.role .l { font-weight: 650; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.role small { color: var(--text-2); font-size: 11.5px; }
.role b { font-size: 22px; line-height: 28px; min-width: 20px; text-align: right; }
.pastille { width: 34px; height: 34px; border-radius: 50%; background: var(--rose); color: var(--accent); display: inline-flex; align-items: center; justify-content: center; flex: none; }
.pastille.blue { background: var(--soft); color: var(--brand); }
@media (max-width: 1500px) { .span-2 .roles { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 500px) { .roles { grid-template-columns: 1fr !important; } }

/* ================= Tables ================= */
.scroll-x { overflow-x: auto; }
.mini { font-size: 13px; }
.mini th, .mini td { padding-top: 9px; padding-bottom: 9px; }
.mini .rank { color: var(--text-2); font-weight: 700; width: 28px; }
.ops td a { color: inherit; }
.ops td a:hover { text-decoration: underline; }
.ops .fam-row td { font-weight: 700; }
.ops .fam-row td:first-child { white-space: nowrap; }
.ops tbody + tbody .fam-row td { border-top: 1px solid var(--border); }
.ops .ch-row td { background: var(--surface-2); font-size: 13px; }
.ops .ch-row td:first-child { padding-left: 62px; }
.ops .ch-row.zero td { color: var(--text-3); }
.ops tfoot td { border-top: 2px solid var(--border-strong); background: var(--surface-2); font-weight: 700; }
.expander { width: 22px; height: 22px; border: 0; background: none; border-radius: 6px; cursor: pointer; color: var(--text-2); display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; margin-right: 4px; }
.expander:hover { background: var(--info-bg); color: var(--link); }
.expander svg { transition: transform .15s; }
.expander svg.opened { transform: rotate(90deg); }
.fam-bar { display: inline-block; width: 4px; height: 16px; border-radius: 2px; vertical-align: middle; margin-right: 8px; background: var(--primary); }
.fam-withdrawals .fam-bar { background: #b91c1c; }
.fam-deposits .fam-bar { background: #047857; }
.fam-payments .fam-bar { background: #1c2aa5; }
.fam-transfers .fam-bar { background: #b45309; }
.stack { display: flex; height: 8px; border-radius: 99px; overflow: hidden; background: #eef0f4; }
.stack.thin { height: 5px; }
.stack span { display: block; height: 100%; }
.st-inline { display: inline-flex; align-items: center; gap: 5px; font-weight: 600; font-size: 13px; white-space: nowrap; }
.empty-sm { text-align: center; padding: 18px 8px; color: var(--text-2); font-size: 13px; }
.empty-sm strong { display: block; color: var(--text); margin-bottom: 2px; }
.empty-board { align-items: center; justify-content: center; padding: 48px 20px; text-align: center; border-style: dashed; }
.empty-board p { color: var(--text-2); margin: 4px 0 12px; }

/* ================= Panneau latéral ================= */
.drawer-mask { position: fixed; inset: 0; background: rgba(15, 23, 42, .35); z-index: 80; }
.drawer { position: fixed; top: 0; right: 0; bottom: 0; width: min(420px, 100vw); background: #fff; z-index: 81; box-shadow: var(--shadow-lg); display: flex; flex-direction: column; }
.drawer header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
.drawer header h2 { margin: 0; font-size: 18px; }
.drawer > p { margin: 12px 20px 4px; font-size: 13px; }
.drawer ul { list-style: none; margin: 0; padding: 4px 12px; overflow-y: auto; flex: 1; }
.drawer label { display: flex; gap: 12px; align-items: flex-start; padding: 10px 8px; border-radius: 10px; cursor: pointer; }
.drawer label:hover { background: var(--surface-2); }
.drawer input { min-height: 0; width: 16px; height: 16px; margin-top: 2px; accent-color: var(--brand); }
.drawer label span { display: flex; flex-direction: column; }
.drawer label small { color: var(--text-2); font-size: 12px; }
.drawer footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid var(--border); }
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.slide-enter-active, .slide-leave-active { transition: transform .22s ease; }
.slide-enter-from, .slide-leave-to { transform: translateX(100%); }
</style>
