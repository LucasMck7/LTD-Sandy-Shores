// ══ Prise/Fin de service via Discord ═══════════════════
const { ActionRowBuilder, ButtonBuilder, ButtonStyle, EmbedBuilder } = require('discord.js');
const { apiPost } = require('../utils/api');

const CHANNEL_SERVICE = '1487063169271726131';

// Envoyer le panneau de service dans un salon
async function sendServiceMessage(channel, client) {
  const embed = new EmbedBuilder()
    .setTitle('⏱ Gestion du service — LTD Sandy Shores')
    .setDescription('Clique sur le bouton correspondant pour prendre ou terminer ton service.')
    .setColor(0xE8C96A)
    .setFooter({ text: 'LTD Sandy Shores • Secrétaire' })
    .setTimestamp();

  const row = new ActionRowBuilder().addComponents(
    new ButtonBuilder().setCustomId('service_prendre').setLabel('▶ Prendre le service').setStyle(ButtonStyle.Success).setEmoji('🟢'),
    new ButtonBuilder().setCustomId('service_terminer').setLabel('■ Terminer le service').setStyle(ButtonStyle.Danger).setEmoji('🔴'),
  );
  return channel.send({ embeds:[embed], components:[row] });
}

// Gérer les interactions (boutons)
async function handleServiceInteraction(interaction, client) {
  const id = interaction.customId;
  const discordId = interaction.user.id;

  // ── Bouton PRENDRE ──
  if (id === 'service_prendre') {
    const employe = await getEmployeByDiscordId(discordId);
    if (!employe) {
      await interaction.reply({ content:'❌ Ton compte Discord nest pas lie a un employe.', flags:64 });
      return;
    }

    try {
      await apiPost('emp_prendre_service', {
        login_id: employe.login_id,
        prenom:   employe.prenom,
        nom:      employe.nom,
        poste:    employe.poste,
        station:  'LTD Sandy Shores',
        ca_debut: 0,
      }, true);

      // Message éphémère de confirmation (visible seulement par l'employé)
      await interaction.reply({ content: `✅ **Service démarré !**
Grade : **${employe.poste}**
Bonne session ${employe.prenom} !`, flags: 64 });

      // Message visible dans le salon de service
      const channel = await client.channels.fetch(CHANNEL_SERVICE).catch(()=>null);
      if (channel) {
        await channel.send({
          embeds: [{
            title: '🟢 Prise de service',
            color: 0x4ade80,
            description: `**${employe.prenom} ${employe.nom}** a pris son service`,
            fields: [{ name:'🏷 Grade', value:employe.poste||'—', inline:true }],
            footer: { text:'LTD Sandy Shores • Secrétaire' },
            timestamp: new Date().toISOString(),
          }],
        });
      }
    } catch(err) {
      await interaction.reply({ content:`❌ Erreur : ${err.message}`, flags:64 });
    }
    return;
  }

  // ── Bouton TERMINER ──
  if (id === 'service_terminer') {
    const employe = await getEmployeByDiscordId(discordId);
    if (!employe) {
      await interaction.reply({ content:'❌ Ton compte Discord nest pas lie a un employe.', flags:64 });
      return;
    }

    try {
      // Récupérer les factures pour calculer le CA de la session
      const factR = await apiPost('emp_factures_get', { discord_id: discordId }).catch(()=>({factures:[]}));
      const factures = factR.factures || [];

      // Récupérer le service actif
      const svcR = await apiPost('emp_get_service_history', { login_id: employe.login_id }).catch(()=>({history:[]}));
      const actif = (svcR.history||[]).find(s => parseInt(s.actif)===1);

      let dureeStr = '—';
      let caSession = 0;

      if (actif && actif.debut) {
        const debut = new Date(actif.debut.replace(' ','T'));
        const dureeMin = Math.floor((Date.now()-debut.getTime())/60000);
        dureeStr = dureeMin>60 ? `${Math.floor(dureeMin/60)}h${String(dureeMin%60).padStart(2,'0')}` : `${dureeMin}min`;

        // Factures émises pendant cette session
        const debutTs = debut.getTime();
        caSession = factures.filter(f => new Date((f.date_facture||'').replace(' ','T')).getTime() >= debutTs)
          .reduce((a,f) => a + parseFloat(f.montant||0), 0);
      }

      await apiPost('emp_fin_service', { login_id: employe.login_id, ca_fin: caSession }, true);

      // Message éphémère
      await interaction.reply({
        content: `✅ **Service terminé !**
⏱ Durée : **${dureeStr}**
💰 CA session : **${caSession.toFixed(2)} $**
Merci ${employe.prenom} !`,
        flags: 64
      });

      // Message dans le salon service
      const channel = await client.channels.fetch(CHANNEL_SERVICE).catch(()=>null);
      if (channel) {
        await channel.send({
          embeds: [{
            title: '🔴 Fin de service',
            color: 0xf87171,
            description: `**${employe.prenom} ${employe.nom}** a terminé son service`,
            fields: [
              { name:'⏱ Durée',      value:dureeStr,                    inline:true },
              { name:'💰 CA session', value:`${caSession.toFixed(2)} $`, inline:true },
              { name:'🏷 Grade',      value:employe.poste||'—',          inline:true },
            ],
            footer: { text:'LTD Sandy Shores • Secrétaire' },
            timestamp: new Date().toISOString(),
          }],
        });
      }
    } catch(err) {
      await interaction.reply({ content:`❌ Erreur : ${err.message}`, flags:64 });
    }
  }
}

async function getEmployeByDiscordId(discordId) {
  try {
    const r = await apiPost('bot_get_employe_by_discord', { discord_id: discordId });
    return r.success ? r.employe : null;
  } catch { return null; }
}

module.exports = { sendServiceMessage, handleServiceInteraction };
