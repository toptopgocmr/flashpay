import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/features_service.dart';
import '../../widgets/fp_ui.dart';
import '../shared/kyc_screen.dart';
import '../shared/support_screen.dart';
import 'gifts_screen.dart';
import 'linked_accounts_screen.dart';
import 'split_bill_screen.dart';
import 'funds_screen.dart';
import '../../l10n/l10n.dart';

/// Zone de services (§4.6.1, §3.5.3) : fonctionnalités FlashPay et
/// répertoire des mini-programmes partenaires classés par catégorie.
class ServicesScreen extends StatefulWidget {
  const ServicesScreen({super.key});

  @override
  State<ServicesScreen> createState() => _ServicesScreenState();
}

class _ServicesScreenState extends State<ServicesScreen> {
  final _service = FeaturesService();
  Map<String, dynamic>? _mini;
  String? _category;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await _service.miniPrograms(category: _category);
      if (mounted) setState(() => _mini = d);
    } catch (_) {}
  }

  void _open(Widget w) => Navigator.push(context, MaterialPageRoute(builder: (_) => w));

  @override
  Widget build(BuildContext context) {
    final tiles = <(IconData, String, Color, VoidCallback)>[
      (Icons.redeem, 'Cadeaux', Color(0xFFE11D48), () => _open(GiftsScreen())),
      (Icons.call_split, 'Partager', FpColors.teal, () => _open(SplitBillScreen())),
      (Icons.link, 'Comptes liés', FpColors.navy, () => _open(LinkedAccountsScreen())),
      (Icons.local_atm, 'Retirer', Color(0xFFA16207), () => _open(FundsScreen(operation: FundsOperation.withdraw))),
      (Icons.verified_user, 'Plafonds', FpColors.success, () => _open(KycScreen())),
      (Icons.support_agent, 'Aide', Colors.blueGrey, () => _open(SupportScreen())),
    ];
    final categories = ((_mini?['categories'] ?? {}) as Map);
    final programs = ((_mini?['programs'] ?? []) as List).cast<Map>();
    return Scaffold(
      appBar: AppBar(title: Text(tr('Services'))),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: EdgeInsets.all(16), children: [
          GridView.count(
            crossAxisCount: 3,
            shrinkWrap: true,
            physics: NeverScrollableScrollPhysics(),
            mainAxisSpacing: 10,
            crossAxisSpacing: 10,
            children: tiles.map((t) => InkWell(
                  onTap: t.$4,
                  borderRadius: BorderRadius.circular(16),
                  child: Container(
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
                    child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                      Icon(t.$1, color: t.$3, size: 30),
                      SizedBox(height: 6),
                      Text(t.$2, style: TextStyle(fontWeight: FontWeight.w600, fontSize: 12.5)),
                    ]),
                  ),
                )).toList(),
          ),
          FpSectionTitle('Mini-programmes', trailing: Text('${programs.length}', style: TextStyle(color: Colors.black45))),
          SizedBox(
            height: 40,
            child: ListView(scrollDirection: Axis.horizontal, children: [
              Padding(padding: EdgeInsets.only(right: 6), child: ChoiceChip(label: Text(tr('Tous')), selected: _category == null, onSelected: (_) { setState(() => _category = null); _load(); })),
              ...categories.entries.map((e) => Padding(
                    padding: EdgeInsets.only(right: 6),
                    child: ChoiceChip(label: Text('${e.value}'), selected: _category == e.key, onSelected: (_) { setState(() => _category = '${e.key}'); _load(); }),
                  )),
            ]),
          ),
          SizedBox(height: 8),
          if (programs.isEmpty) FpEmpty('Aucun mini-programme dans cette catégorie pour le moment.', icon: Icons.apps),
          ...programs.map((p) => Card(child: ListTile(
                leading: p['icon_url'] != null
                    ? ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.network('${p['icon_url']}', width: 40, height: 40, errorBuilder: (_, __, ___) => Icon(Icons.apps)))
                    : Icon(Icons.apps, color: FpColors.navy),
                title: Text('${p['name']}'),
                subtitle: Text('${p['description'] ?? p['merchant']?['business_name'] ?? ''}'),
                trailing: Icon(Icons.open_in_new),
                onTap: () async {
                  final ok = await launchUrl(Uri.parse('${p['entry_url']}'), mode: LaunchMode.inAppBrowserView);
                  if (!ok && context.mounted) fpSnack(context, 'Impossible d\'ouvrir ce service.', error: true);
                },
              ))),
        ]),
      ),
    );
  }
}
