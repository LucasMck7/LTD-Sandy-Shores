const { SlashCommandBuilder } = require('discord.js');
const { apiPost } = require('../utils/api');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('fiche')
    .setDescription('Voir la fiche d\'un employé')
    .addUserOption(o => o.setName('membre').setDescription('Membre Discord').setRequired(false))
    .addStringOption(o => o.setName('nom').setDescription('Nom de l\'employé').setRequired(false)),

  async execute(interaction) {
    await interaction.deferReply({ ephemeral: true });
    const membre = interaction.options.getUser('membre');
    const nom    = interaction.options.getString('nom');

    try {
      let employe = null;

      if (membre) {
        const r = await apiPost('bot_get_employe_by_discord', { discord_id: membre.id });
        if (r.success) employe = r.employe;
      } else if (nom) {
        const r = await apiPost('get_employes_search', { q: nom }, true).catch(()=>null);
        if (r && r.employes && r.employes.length) employe = r.employes[0];
      } else {
        // Chercher sa propre fiche
        const r = await apiPost('bot_get_employe_by_discord', { discord_id: interaction.user.id });
        if (r.success) employe = r.employe;
      }

      if (!employe) {
        await interaction.editReply('❌ Employé non trouvé. Assure-toi que ton compte Discord est lié.');
        return;
      }

      // Récupérer l'historique de service
      const hist = await apiPost('emp_get_service_history', { login_id: employe.login_id }).catch(()=>({history:[]}));
      const services = hist.history || [];
      const now = new Date();
      const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
      const servicesMois = services.filter(s => new Date((s.debut||'').replace(' ','T')) >= monthStart);
      const caTotal = services.reduce((a,s) => a + Math.max(0, parseFloat(s.ca_fin||0) - parseFloat(s.ca_debut||0)), 0);
      const caMois  = servicesMois.reduce((a,s) => a + Math.max(0, parseFloat(s.ca_fin||0) - parseFloat(s.ca_debut||0)), 0);

      const poleColor = ['Vendeur','Responsable des Ventes'].some(p => (employe.poste||'').includes(p)) ? 0x3B82F6 : 0x22C55E;

      await interaction.editReply({
        embeds: [{
          title: `👤 ${employe.prenom} ${employe.nom}`,
          color: poleColor,
          thumbnail: employe.avatar_url ? { url: employe.avatar_url } : undefined,
          fields: [
            { name:'🏷 Grade',          value: employe.poste||'—',                        inline:true },
            { name:'🔑 Login',          value: `\`${employe.login_id||'—'}\``,            inline:true },
            { name:'📅 Sessions/mois',  value: String(servicesMois.length),               inline:true },
            { name:'💰 CA ce mois',     value: `${caMois.toFixed(2)} $`,                  inline:true },
            { name:'💰 CA total',       value: `${caTotal.toFixed(2)} $`,                 inline:true },
            { name:'📞 Téléphone',      value: employe.telephone||'—',                   inline:true },
          ],
          footer:{ text:'LTD Sandy Shores • Secrétaire' },
          timestamp: new Date().toISOString(),
        }],
      });
    } catch(err) { await interaction.editReply(`❌ Erreur : ${err.message}`); }
  },
};
