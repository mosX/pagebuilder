<?php

namespace App\Enums;

enum Permission: string
{
    case CompanyView = 'company.view';
    case CompanyUpdate = 'company.update';
    case MembersView = 'members.view';
    case MembersManage = 'members.manage';
    case SchemaManage = 'schema.manage';
    case ContentView = 'content.view';
    case ContentManage = 'content.manage';
}
