import 'package:flutter/material.dart';
import '../../models/transaction.dart';
import '../../services/merchant_service.dart';
import '../../widgets/transaction_tile.dart';
import '../../l10n/l10n.dart';

class MerchantCollectionsScreen extends StatefulWidget {
  const MerchantCollectionsScreen({super.key});

  @override
  State<MerchantCollectionsScreen> createState() => _MerchantCollectionsScreenState();
}

class _MerchantCollectionsScreenState extends State<MerchantCollectionsScreen> {
  final _merchantService = MerchantService();
  List<FpTransaction> _items = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final data = await _merchantService.collections();
    setState(() {
      _items = (data['data'] as List).map((e) => FpTransaction.fromJson(e)).toList();
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('Mes encaissements'))),
      body: _loading
          ? Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: _items.isEmpty
                  ? ListView(children: [Padding(padding: EdgeInsets.all(32), child: Text(tr('Aucun encaissement pour le moment.')))])
                  : ListView.builder(
                      itemCount: _items.length,
                      itemBuilder: (context, i) => TransactionTile(transaction: _items[i]),
                    ),
            ),
    );
  }
}
