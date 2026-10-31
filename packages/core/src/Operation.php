<?php

namespace AiphraCore;

enum Operation: string {
    case Get = 'get';
    case Add = 'add';
    case Edit = 'edit';
    case Delete = 'delete';
}
